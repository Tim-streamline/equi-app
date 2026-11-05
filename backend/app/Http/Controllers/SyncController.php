<?php

namespace App\Http\Controllers;

use App\Models;
use App\Support\IntakeSubmission;
use App\Support\ProtocolAccess;
use App\Support\ClientCapabilities;
use App\Support\LegacyProtocolClient;
use App\Support\ProtocolDayHistory;
use App\Support\SyncAccessPolicy;
use Carbon\CarbonImmutable;
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
        'library_filter_groups' => Models\LibraryFilterGroup::class,
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
        'intake_answers' => Models\IntakeAnswer::class,
    ];

    public function upload(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('powersync_user_id');

        $payload = $request->validate([
            'timezone' => ['sometimes', 'timezone'],
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

        DB::transaction(function () use ($request, $payload, &$applied, &$skipped, $policy, $userId) {
            $newSubmissions = [];
            foreach ($payload['operations'] as $op) {
                if ($op['type'] === 'protocol_supplement_intakes') {
                    // Offline devices can create different IDs for the same product-day.
                    // Resolve previously accepted IDs before the ordinary ownership/identity checks.
                    $op['id'] = DB::table('protocol_intake_client_ids')->where('user_id', $userId)
                        ->where('client_id', $op['id'])->value('intake_id') ?? $op['id'];
                }
                abort_if(in_array($op['type'], ['library_item_access', 'library_contents'], true), 403, 'Library access and content are server-managed.');
                abort_if($op['type'] === 'intake_responses', 409, 'Werk de app bij om de intake te synchroniseren.');
                // Shelby's chat is written by the server only, so answers cannot be forged from a device.
                // Older app versions still queue local placeholder messages; skip them instead of blocking sync.
                if (in_array($op['type'], ['chat_sessions', 'chat_messages'], true)) {
                    $skipped[] = $op['type'].':server-managed';

                    continue;
                }
                $modelClass = self::TABLE_TO_MODEL[$op['type']] ?? null;
                if (! $modelClass) {
                    $skipped[] = $op['type'];

                    continue;
                }
                $recoverAnswer = $op['type'] === 'intake_answers' && $op['op'] === 'PATCH' && ! Models\IntakeAnswer::whereKey($op['id'])->exists();
                if ($op['type'] === 'intake_answers' && $op['op'] === 'DELETE' && ! Models\IntakeAnswer::whereKey($op['id'])->exists()) {
                    $skipped[] = 'intake_answers:removed';

                    continue;
                }
                if ($recoverAnswer) {
                    $this->validateAnswerRecovery($op);
                }
                $policy->authorize($userId, $op);
                if ($op['type'] === 'intake_bookings') {
                    $booking = Models\IntakeBooking::find($op['id']);
                    app(ProtocolAccess::class)->authorizeBooking($userId, $op['data']['horse_id'] ?? $booking?->horse_id, $op['id']);
                } elseif ($op['type'] === 'intake_answers') {
                    $answer = Models\IntakeAnswer::find($op['id']);
                    $booking = Models\IntakeBooking::findOrFail($op['data']['response_id'] ?? $answer?->response_id);
                    app(ProtocolAccess::class)->authorizeBooking($userId, $booking->horse_id, $booking->id);
                }
                if (in_array($op['type'], ['intake_bookings', 'intake_answers'], true)) {
                    $locked = Models\IntakeBooking::lockForUpdate()->findOrFail($booking->id);
                    if ($locked->completed_at || $locked->submitted_at) {
                        // Acknowledge stale offline writes without applying them, so one
                        // completed intake cannot block the device's entire sync queue.
                        $skipped[] = $op['type'].':completed';

                        continue;
                    }
                }
                // Cleanup uses the same booking lock. Recheck after acquiring it
                // in case the row disappeared while this request was waiting.
                $recoverAnswer = $op['type'] === 'intake_answers' && $op['op'] === 'PATCH' && ! Models\IntakeAnswer::whereKey($op['id'])->exists();
                if ($recoverAnswer) {
                    $this->validateAnswerRecovery($op);
                    abort_if($locked->status === 'cancelled', 403, 'Deze intake is geannuleerd.');
                    $cleanup = app(\App\Support\IntakeObsoleteAnswers::class);
                    $field = $cleanup->fieldFor($op['data']['section_id'], $op['data']['field_id']);
                    $value = json_decode($op['data']['value'] ?? 'null', true);
                    if (! $field || $value === null || $value === '' || $value === [] || $cleanup->obsolete($field, $value)) {
                        $skipped[] = 'intake_answers:removed';

                        continue;
                    }
                    // Another device may already have answered this question with
                    // a new row ID. Apply the correction under the booking lock.
                    $replacement = $booking->answers()->where('section_id', $op['data']['section_id'])
                        ->where('field_id', $op['data']['field_id'])->first();
                    $op['id'] = $replacement?->id ?? $op['id'];
                    $op['op'] = 'PUT';
                    $op['data'] = array_intersect_key($op['data'], array_flip(['response_id', 'section_id', 'field_id', 'value']));
                }
                if ($op['type'] === 'intake_bookings') {
                    $data = $op['data'] ?? [];
                    if ($op['op'] !== 'DELETE' && (($data['intake_status'] ?? '') === 'submitted'
                        || ! empty($data['submitted_at']) || ! empty($data['completed_at']))) {
                        $newSubmissions[] = $op['id'];
                    }
                    // Completion timestamps and review state are server-managed.
                    $op['data'] = array_intersect_key($data, array_flip([
                        'user_id', 'horse_id', 'therapist_id', 'scheduled_at', 'slot_label',
                        'duration_minutes', 'status', 'notes', 'started_at',
                    ]));
                    validator($op['data'], [
                        'status' => ['sometimes', 'in:pending,confirmed,done,cancelled'],
                        'started_at' => ['sometimes', 'nullable', 'date'],
                    ])->validate();
                }

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
                if ($op['type'] === 'library_progress' && $op['op'] !== 'DELETE' && isset($op['data']['last_viewed_at'])) {
                    // Two offline devices may have created different row IDs for
                    // the same view. Merge by owner/item and retain newer history.
                    $existing = Models\LibraryProgress::find($op['id']);
                    abort_if($existing && $existing->user_id !== $userId, 403);
                    $view = validator($op['data'] + ['item_id' => $existing?->item_id], [
                        'item_id' => ['required', 'uuid'],
                        'last_viewed_at' => ['required', 'date'],
                    ])->validate();
                    // A lesson can be deleted while a device is offline. Its view
                    // history is obsolete, and must not block later answers/photos.
                    // Lock surviving items until the write to prevent a deletion race.
                    if (! Models\LibraryItem::whereKey($view['item_id'])->sharedLock()->first(['id'])) {
                        $skipped[] = 'library_progress:removed-item';

                        continue;
                    }
                    DB::statement('INSERT INTO library_progress (id,user_id,item_id,last_viewed_at,progress,position_sec,completed,created_at,updated_at) VALUES (?,?,?,?,0,0,false,?,?) ON CONFLICT (user_id,item_id) DO UPDATE SET last_viewed_at = GREATEST(library_progress.last_viewed_at, EXCLUDED.last_viewed_at), updated_at = EXCLUDED.updated_at',
                        [$op['id'], $userId, $view['item_id'], $view['last_viewed_at'], now(), now()]);
                    $applied++;

                    continue;
                }
                $intakeDay = $op['type'] === 'protocol_supplement_intakes' ? $this->intakeDay($op) : null;
                $historical = $intakeDay ? $this->validateIntake($op, $payload['timezone'] ?? null) : null;
                if ($intakeDay && $op['op'] === 'PUT'
                    && ! Models\ProtocolSupplementIntake::whereKey($op['id'])->exists()) {
                    // validateIntake holds the protocol lock, so concurrent devices converge on
                    // one row. Retain the alternate ID for later PATCH/DELETE operations too.
                    $canonicalId = Models\ProtocolSupplementIntake::where('protocol_phase_supplement_id', $intakeDay[0])
                        ->where('date', $intakeDay[1])->where('administration', $op['data']['administration'] ?? 1)->value('id');
                    if ($canonicalId) {
                        DB::table('protocol_intake_client_ids')->updateOrInsert(['user_id' => $userId, 'client_id' => $op['id']], ['intake_id' => $canonicalId]);
                        $op['id'] = $canonicalId;
                    }
                }
                if ($intakeDay && $op['op'] !== 'DELETE') {
                    $op = $this->withRecordedDosage($op, $intakeDay, $historical);
                }
                $legacyPut = $op['op'] === 'PUT' && ! array_key_exists('administration', $op['data'] ?? []);
                if ($intakeDay && (! ClientCapabilities::protocolDoses($request) || $legacyPut)) {
                    // A released app's whole-day checkbox can remain queued across an upgrade.
                    // New inserts name their dose; PATCH payloads cannot identify the old format.
                    $this->applyWholeDayIntake($op, $intakeDay, $historical);
                    $applied++;

                    continue;
                }
                if ($intakeDay && $op['op'] === 'DELETE'
                    && (Models\ProtocolSupplementIntake::find($op['id'])?->administration === 1
                        || app(\App\Support\ProtocolDoseBuildup::class)->enabled(Models\ProtocolPhaseSupplement::find($intakeDay[0])?->dose_buildup))) {
                    // Retain recorded dates for buildup, including unchecked doses and offline aliases.
                    $this->patch($modelClass, $op['id'], ['done' => false, 'taken_at' => null]);
                } else {
                    $this->applyOp($modelClass, $op);
                }
                if ($op['type'] === 'intake_answers' && isset($booking)) {
                    app(\App\Support\IntakeObsoleteAnswers::class)->discardObsoleteAnswer(Models\IntakeAnswer::find($op['id']));
                    $booking->forceFill(['last_activity_at' => now(), 'last_section' => $op['data']['section_id'] ?? $answer?->section_id ?? $booking->last_section,
                        'last_field' => $op['data']['field_id'] ?? $answer?->field_id ?? $booking->last_field])->save();
                }
                if ($historical) {
                    if ($historical['scheduled']) {
                        app(ProtocolDayHistory::class)->setDone($historical['protocol'], $historical['date'], $historical['item_id'],
                            $historical['done'], $historical['now'], false, $historical['administration']);
                    } else {
                        // Retain the offline record without adding a dose to a frozen day.
                        app(LegacyProtocolClient::class)->refreshDayDone($historical['item_id'], $historical['date'], $historical['dose_count']);
                    }
                } elseif ($intakeDay) {
                    // Only scheduled doses count toward today's whole-day checkmark.
                    [$itemId, $date] = $intakeDay;
                    $item = Models\ProtocolPhaseSupplement::find($itemId);
                    if ($item) {
                        app(LegacyProtocolClient::class)->refreshDayDone($itemId, $date, $item->administrationsPerDay());
                    }
                }
                $applied++;
            }
            foreach (array_unique($newSubmissions) as $id) {
                app(IntakeSubmission::class)->complete(Models\IntakeBooking::lockForUpdate()->findOrFail($id));
            }
        });

        if ($skipped) {
            Log::warning('[sync] skipped unsupported or obsolete operations', ['tables' => array_values(array_unique($skipped))]);
        }

        return response()->json([
            'applied' => $applied,
            'skipped' => count($skipped),
            'user_id' => $userId,
        ]);
    }

    private function validateAnswerRecovery(array $op): void
    {
        // The connector supplies identity without replacing the queued value.
        // Keep unidentifiable corrections queued instead of acknowledging loss.
        abort_unless(isset($op['data']['response_id'], $op['data']['section_id'], $op['data']['field_id']),
            409, 'De intakevraag ontbreekt bij deze wijziging. Werk de app bij en synchroniseer opnieuw.');
        validator($op, ['id' => ['required', 'uuid'], 'data.response_id' => ['required', 'uuid'],
            'data.section_id' => ['required', 'string', 'max:64'], 'data.field_id' => ['required', 'string', 'max:64'],
            'data.value' => ['sometimes', 'nullable', 'json']])->validate();
    }

    /** [item ID, date] of an intake operation, or null for a PATCH/DELETE of an unknown row (a no-op). */
    private function intakeDay(array $op): ?array
    {
        $existing = Models\ProtocolSupplementIntake::find($op['id']);
        if (! $existing && $op['op'] !== 'PUT') {
            return null;
        }

        return $existing ? [$existing->protocol_phase_supplement_id, $existing->date->toDateString()]
            : [(string) ($op['data']['protocol_phase_supplement_id'] ?? ''), (string) ($op['data']['date'] ?? '')];
    }

    /** Keep the amount the client actually displayed, including delayed offline uploads. */
    private function withRecordedDosage(array $op, array $intakeDay, ?array $historical): array
    {
        [$itemId, $date] = $intakeDay;
        $existing = Models\ProtocolSupplementIntake::find($op['id']);
        if ($existing) {
            $op['data']['dosage'] = $existing->dosage;
            return $op;
        }
        $item = Models\ProtocolPhaseSupplement::findOrFail($itemId);
        $sameDay = $item->intakes()->where('date', $date)->orderBy('created_at')->orderBy('id')->first();
        if ($sameDay) {
            $op['data']['dosage'] = $sameDay->dosage;
        } elseif (! array_key_exists('dosage', $op['data'])) {
            $frozen = $historical ? app(ProtocolDayHistory::class)->day($historical['protocol'], $date, $historical['now']) : null;
            $op['data']['dosage'] = $frozen
                ? collect($frozen['items'])->firstWhere('id', $itemId)['dosage']
                : app(\App\Support\ProtocolDoseBuildup::class)->forDay($item, $date);
        }
        validator($op['data'], ['dosage' => ['nullable', 'string', 'max:255']])->validate();

        return $op;
    }

    /**
     * Apply a released app's checkbox to every scheduled dose of that product and day,
     * after the same ownership, identity, date and plan validation as a single dose.
     *
     * @param  array{0: string, 1: string}  $intakeDay
     * @param  array|null  $historical  validateIntake() result: history arguments, or null for today
     */
    private function applyWholeDayIntake(array $op, array $intakeDay, ?array $historical): void
    {
        [$itemId, $date] = $intakeDay;
        $existing = Models\ProtocolSupplementIntake::find($op['id']);
        $done = $op['op'] !== 'DELETE' && (bool) (($op['data'] ?? [])['done'] ?? $existing?->done);
        $takenAt = $done ? (($op['data'] ?? [])['taken_at'] ?? now()) : null;
        $item = Models\ProtocolPhaseSupplement::findOrFail($itemId);
        // The app keeps its own row ID; reuse it for the first dose when that is still free.
        if (! $existing && ! Models\ProtocolSupplementIntake::where('protocol_phase_supplement_id', $itemId)->where('date', $date)->where('administration', 1)->exists()) {
            $this->upsert(Models\ProtocolSupplementIntake::class, $op['id'], ['protocol_phase_supplement_id' => $itemId,
                'horse_id' => $op['data']['horse_id'], 'date' => $date, 'dosage' => $op['data']['dosage'] ?? $item->dosage,
                'administration' => 1, 'done' => $done, 'taken_at' => $takenAt]);
        }
        if ($historical) {
            app(ProtocolDayHistory::class)->setDone($historical['protocol'], $date, $itemId, $done, $historical['now'], true, null);

            return;
        }
        $horseId = $existing?->horse_id ?? $op['data']['horse_id'];
        $dosage = app(\App\Support\ProtocolDoseBuildup::class)->forDay($item->fresh(), $date);
        foreach (range(1, $item->administrationsPerDay()) as $administration) {
            Models\ProtocolSupplementIntake::updateOrCreate(
                ['protocol_phase_supplement_id' => $itemId, 'date' => $date, 'administration' => $administration],
                ['horse_id' => $horseId, 'dosage' => $dosage, 'done' => $done, 'taken_at' => $takenAt],
            );
        }
        app(LegacyProtocolClient::class)->refreshDayDone($itemId, $date, $item->administrationsPerDay());
    }

    /**
     * Valid offline records survive frequency changes; daily frequency controls display, not storage.
     *
     * @return array{protocol: Models\Protocol, date: string, item_id: string, done: bool, now: CarbonImmutable, administration: int, scheduled: bool, dose_count: int}|null
     */
    private function validateIntake(array $op, ?string $timezone): ?array
    {
        $existing = Models\ProtocolSupplementIntake::find($op['id']);
        if (! $existing && $op['op'] !== 'PUT') {
            return null;
        }
        $data = array_merge($existing?->getAttributes() ?? [], $op['data'] ?? []);
        $data['administration'] ??= 1;
        validator($data, ['date' => ['required', 'date_format:Y-m-d'], 'done' => ['required', 'boolean'], 'administration' => ['required', 'integer', 'between:1,24']])->validate();
        if ($existing) {
            foreach (['date', 'horse_id', 'protocol_phase_supplement_id', 'administration'] as $key) {
                abort_unless((string) $data[$key] === (string) $existing->getRawOriginal($key), 422, 'Een registratie kan niet naar een andere dag of een ander item worden verplaatst.');
            }
        }
        $item = Models\ProtocolPhaseSupplement::findOrFail($data['protocol_phase_supplement_id']);
        $history = app(ProtocolDayHistory::class);
        $protocol = Models\Protocol::query()->lockForUpdate()->findOrFail($item->phase->protocol_id);
        $now = CarbonImmutable::now($timezone ?? $history->timezone($protocol));
        abort_unless($protocol->published_at && $protocol->published_at->lte($now) && $protocol->status === 'active', 422);
        abort_unless($data['date'] >= $now->subDays(14)->toDateString() && $data['date'] <= $now->toDateString(), 422, 'Alleen vandaag en de afgelopen 14 dagen kunnen worden aangepast.');
        if ($data['date'] < $now->toDateString()) {
            $day = $history->day($protocol, $data['date'], $now);
            $doses = collect($day['items'])->where('id', $item->id);
            abort_if($doses->isEmpty(), 422, 'Deze gift stond niet op deze dag gepland.');
            return ['protocol' => $protocol, 'date' => $data['date'], 'item_id' => $item->id,
                'done' => $op['op'] === 'DELETE' ? false : (bool) $data['done'], 'now' => $now,
                'administration' => (int) $data['administration'], 'dose_count' => $doses->count(),
                'scheduled' => $doses->contains(fn ($row) => ($row['administration'] ?? 1) === (int) $data['administration'])];
        }
        $start = $protocol->started_at ? CarbonImmutable::parse($protocol->started_at->toDateString(), $now->timezone) : null;
        $week = $start ? (int) floor($start->diffInDays($now->startOfDay(), false) / 7) + 1 : 0;
        abort_unless($week > 0 && $item->weeks()->whereHas('protocolPhaseWeek', fn ($query) => $query->where('protocol_week_number', $week))->exists(), 422, 'Dit item staat vandaag niet gepland.');

        return null;
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
