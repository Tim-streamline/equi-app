<?php

namespace App\Http\Controllers;

use App\Models;
use App\Support\SyncAccessPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a batch of PowerSync CRUD operations to the Postgres database.
 *
 * The client (Expo BackendConnector.uploadData) posts here whenever the
 * local PowerSync SQLite has pending writes:
 *
 *     POST /api/sync/upload
 *     {
 *       "operations": [
 *         { "op": "PUT" | "PATCH" | "DELETE",
 *           "type": "<table_name>",
 *           "id":   "<uuid>",
 *           "data": { ... } | null }
 *       ]
 *     }
 *
 * Auth: middleware AuthenticatePowerSyncJwt verifies the Bearer JWT; the
 * acting user's UUID is available via $request->attributes->get('powersync_user_id').
 *
 * Authorisation: every operation is checked against row-level ownership
 * before it is applied. Reference and server-managed tables are read-only
 * through sync; app-owned rows must belong to the JWT subject.
 */
class SyncController extends Controller
{
    /**
     * Map table name → Eloquent model class. Tables not in this map are
     * silently skipped so a misbehaving client can't poke at framework
     * tables like `sessions` or `password_reset_tokens`.
     */
    private const TABLE_TO_MODEL = [
        'users' => Models\User::class,
        'therapists' => Models\Therapist::class,
        'horses' => Models\Horse::class,
        'horse_shares' => Models\HorseShare::class,
        'horse_stats' => Models\HorseStat::class,
        'timeline_events' => Models\TimelineEvent::class,
        'protocols' => Models\Protocol::class,
        'protocol_phases' => Models\ProtocolPhase::class,
        'protocol_phase_weeks' => Models\ProtocolPhaseWeek::class,
        'protocol_phase_supplements' => Models\ProtocolPhaseSupplement::class,
        'protocol_phase_supplement_weeks' => Models\ProtocolPhaseSupplementWeek::class,
        'protocol_analyses' => Models\ProtocolAnalysis::class,
        'protocol_advice' => Models\ProtocolAdvice::class,
        'protocol_supplement_intakes' => Models\ProtocolSupplementIntake::class,
        'observations' => Models\Observation::class,
        'observation_photos' => Models\ObservationPhoto::class,
        'products' => Models\Product::class,
        'ingredients' => Models\Ingredient::class,
        'scan_results' => Models\ScanResult::class,
        'scan_ingredients' => Models\ScanIngredient::class,
        'library_items' => Models\LibraryItem::class,
        'library_chapters' => Models\LibraryChapter::class,
        'library_article_sections' => Models\LibraryArticleSection::class,
        'library_categories' => Models\LibraryCategory::class,
        'library_item_categories' => Models\LibraryItemCategory::class,
        'library_bookmarks' => Models\LibraryBookmark::class,
        'library_progress' => Models\LibraryProgress::class,
        'seasonal_tips' => Models\SeasonalTip::class,
        'community_categories' => Models\CommunityCategory::class,
        'community_tags' => Models\CommunityTag::class,
        'community_posts' => Models\CommunityPost::class,
        'community_post_tags' => Models\CommunityPostTag::class,
        'community_replies' => Models\CommunityReply::class,
        'community_reactions' => Models\CommunityReaction::class,
        'plans' => Models\Plan::class,
        'plan_benefits' => Models\PlanBenefit::class,
        'subscriptions' => Models\Subscription::class,
        'payments' => Models\Payment::class,
        'notification_preferences' => Models\NotificationPreference::class,
        'user_home_preferences' => Models\UserHomePreference::class,
        'account_settings' => Models\AccountSetting::class,
        'data_exports' => Models\DataExport::class,
        'chat_sessions' => Models\ChatSession::class,
        'chat_messages' => Models\ChatMessage::class,
        'nova_fallback_replies' => Models\NovaFallbackReply::class,
        'intake_bookings' => Models\IntakeBooking::class,
        'intake_responses' => Models\IntakeResponse::class,
        'intake_answers' => Models\IntakeAnswer::class,
    ];

    public function upload(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('powersync_user_id');

        $payload = $request->validate([
            'operations' => 'required|array',
            'operations.*.op' => 'required|in:PUT,PATCH,DELETE',
            'operations.*.type' => 'required|string',
            'operations.*.id' => 'required|string',
            'operations.*.data' => 'nullable|array',
        ]);

        $applied = 0;
        $skipped = [];

        abort_if(! is_string($userId) || $userId === '', 401, 'Missing PowerSync subject.');

        $policy = new SyncAccessPolicy;

        DB::transaction(function () use ($payload, &$applied, &$skipped, $policy, $userId) {
            foreach ($payload['operations'] as $op) {
                $modelClass = self::TABLE_TO_MODEL[$op['type']] ?? null;
                if (! $modelClass) {
                    $skipped[] = $op['type'];

                    continue;
                }
                $policy->authorize($userId, $op);
                if ($op['type'] === 'user_home_preferences') {
                    $op['data'] = $this->homePreferenceData($op['data'] ?? []);
                }
                if ($op['op'] !== 'DELETE' && in_array($op['type'], ['intake_bookings', 'horse_shares'], true)
                    && array_key_exists('therapist_id', $op['data'] ?? [])) {
                    $currentTherapist = $modelClass::find($op['id'])?->therapist_id;
                    validator($op['data'], ['therapist_id' => ['nullable', Models\Therapist::assignmentRule($currentTherapist)]], [
                        'therapist_id.exists' => 'Deze therapist is gearchiveerd of niet beschikbaar voor nieuwe koppelingen.',
                    ])->validate();
                }
                $this->applyOp($modelClass, $op);
                $applied++;
            }
        });

        if ($skipped) {
            Log::warning('[sync] skipped unknown tables', ['tables' => array_values(array_unique($skipped))]);
        }

        return response()->json([
            'applied' => $applied,
            'skipped' => count($skipped),
            'user_id' => $userId,
        ]);
    }

    private function homePreferenceData(array $data): array
    {
        // PowerSync uploads SQLite JSON columns as strings; Eloquent expects
        // decoded values for its array cast, otherwise they get encoded twice.
        if (isset($data['dismissed_tip_ids']) && is_string($data['dismissed_tip_ids'])) {
            validator(['ids' => $data['dismissed_tip_ids']], ['ids' => ['json']])->validate();
            $data['dismissed_tip_ids'] = json_decode($data['dismissed_tip_ids'], true);
        }

        return validator(['data' => $data], [
            'data' => ['array:seasonal_tips_enabled,dismissed_tip_ids'],
            'data.seasonal_tips_enabled' => ['sometimes', 'boolean'],
            'data.dismissed_tip_ids' => ['sometimes', 'array', 'max:10000'],
            'data.dismissed_tip_ids.*' => ['uuid', 'distinct'],
        ])->validate()['data'];
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function applyOp(string $modelClass, array $op): void
    {
        $id = $op['id'];
        $data = $op['data'] ?? [];

        match ($op['op']) {
            'PUT' => $this->upsert($modelClass, $id, $data),
            'PATCH' => $this->patch($modelClass, $id, $data),
            'DELETE' => $this->delete($modelClass, $id),
        };
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function upsert(string $modelClass, string $id, array $data): void
    {
        $instance = $modelClass::find($id);
        if ($instance) {
            $instance->fill($data)->save();

            return;
        }

        $instance = new $modelClass;
        $instance->setAttribute('id', $id);
        $instance->fill($data);
        $instance->save();
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function patch(string $modelClass, string $id, array $data): void
    {
        if (! $data) {
            return;
        }
        $instance = $modelClass::find($id);
        if (! $instance) {
            // PATCH against a missing row is a no-op.
            return;
        }
        $instance->fill($data)->save();
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function delete(string $modelClass, string $id): void
    {
        $instance = $modelClass::find($id);
        if (! $instance) {
            // DELETE against a missing row is a no-op.
            return;
        }
        $instance->delete();
    }
}
