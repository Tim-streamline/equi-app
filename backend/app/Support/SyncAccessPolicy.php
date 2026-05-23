<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SyncAccessPolicy
{
    private const READ_ONLY_TABLES = [
        'account_settings',
        'community_categories',
        'community_tags',
        'data_exports',
        'focus_topics',
        'horse_stats',
        'ingredients',
        'library_article_sections',
        'library_categories',
        'library_chapters',
        'library_item_categories',
        'library_item_focus',
        'library_items',
        'nova_fallback_replies',
        'payments',
        'plan_benefits',
        'plans',
        'products',
        'protocol_advice',
        'protocol_analyses',
        'protocol_phase_items',
        'protocol_phases',
        'protocol_tasks',
        'protocols',
        'seasonal_tips',
        'subscriptions',
        'therapists',
        'timeline_events',
    ];

    public function authorize(string $userId, array $operation): void
    {
        $table = $operation['type'];
        $id = $operation['id'];
        $op = $operation['op'];
        $data = $operation['data'] ?? [];

        if (in_array($table, self::READ_ONLY_TABLES, true)) {
            $this->deny($table);
        }

        $existing = $this->row($table, $id);
        $allowed = match ($table) {
            'users' => $this->ownsSelfRow($userId, $id, $op),
            'horses' => $this->ownsHorseRow($userId, $existing, $data),
            'horse_focus', 'horse_shares' => $this->ownsHorseByRowHorseId($userId, $existing, $data),
            'protocol_task_completions' => $this->ownsTaskCompletion($userId, $existing, $data),
            'observations' => $this->ownsObservation($userId, $existing, $data),
            'observation_photos' => $this->ownsObservationPhoto($userId, $existing, $data),
            'scan_results' => $this->ownsScanResult($userId, $existing, $data),
            'scan_ingredients' => $this->ownsScanIngredient($userId, $existing, $data),
            'library_bookmarks', 'library_progress', 'notification_preferences' => $this->ownsUserRow($userId, $existing, $data),
            'community_posts' => $this->ownsCommunityPost($userId, $existing, $data),
            'community_post_tags' => $this->ownsCommunityPostTag($userId, $existing, $data),
            'community_replies' => $this->ownsCommunityReply($userId, $existing, $data),
            'community_reactions' => $this->ownsUserRow($userId, $existing, $data),
            'chat_sessions' => $this->ownsChatSession($userId, $existing, $data),
            'chat_messages' => $this->ownsChatMessage($userId, $existing, $data),
            'intake_bookings' => $this->ownsIntakeBooking($userId, $existing, $data),
            default => false,
        };

        if (! $allowed) {
            $this->deny($table);
        }
    }

    private function ownsSelfRow(string $userId, string $id, string $op): bool
    {
        return $op !== 'DELETE' && $id === $userId;
    }

    private function ownsHorseRow(string $userId, ?object $existing, array $data): bool
    {
        $ownerId = $this->value('owner_id', $existing, $data);

        return $ownerId === $userId;
    }

    private function ownsHorseByRowHorseId(string $userId, ?object $existing, array $data): bool
    {
        $horseId = $this->value('horse_id', $existing, $data);

        return is_string($horseId) && $this->ownsHorse($userId, $horseId);
    }

    private function ownsTaskCompletion(string $userId, ?object $existing, array $data): bool
    {
        $horseId = $this->value('horse_id', $existing, $data);
        if (! is_string($horseId) || ! $this->ownsHorse($userId, $horseId)) {
            return false;
        }

        $taskId = $this->value('task_id', $existing, $data);
        if (! is_string($taskId)) {
            return true;
        }

        return DB::table('protocol_tasks')
            ->join('protocols', 'protocols.id', '=', 'protocol_tasks.protocol_id')
            ->where('protocol_tasks.id', $taskId)
            ->where('protocols.horse_id', $horseId)
            ->exists();
    }

    private function ownsObservation(string $userId, ?object $existing, array $data): bool
    {
        $authorId = $this->value('author_id', $existing, $data);
        $horseId = $this->value('horse_id', $existing, $data);

        return $authorId === $userId
            && is_string($horseId)
            && $this->ownsHorse($userId, $horseId);
    }

    private function ownsObservationPhoto(string $userId, ?object $existing, array $data): bool
    {
        $observationId = $this->value('observation_id', $existing, $data);
        if (! is_string($observationId)) {
            return false;
        }

        return DB::table('observations')
            ->join('horses', 'horses.id', '=', 'observations.horse_id')
            ->where('observations.id', $observationId)
            ->where('observations.author_id', $userId)
            ->where('horses.owner_id', $userId)
            ->exists();
    }

    private function ownsScanResult(string $userId, ?object $existing, array $data): bool
    {
        $rowUserId = $this->value('user_id', $existing, $data);
        if ($rowUserId !== $userId) {
            return false;
        }

        $horseId = $this->value('horse_id', $existing, $data);

        return ! is_string($horseId) || $horseId === '' || $this->ownsHorse($userId, $horseId);
    }

    private function ownsScanIngredient(string $userId, ?object $existing, array $data): bool
    {
        $scanId = $this->value('scan_id', $existing, $data);
        if (! is_string($scanId)) {
            return false;
        }

        return DB::table('scan_results')
            ->where('id', $scanId)
            ->where('user_id', $userId)
            ->exists();
    }

    private function ownsUserRow(string $userId, ?object $existing, array $data): bool
    {
        return $this->value('user_id', $existing, $data) === $userId;
    }

    private function ownsCommunityPost(string $userId, ?object $existing, array $data): bool
    {
        return $this->value('author_user_id', $existing, $data) === $userId;
    }

    private function ownsCommunityPostTag(string $userId, ?object $existing, array $data): bool
    {
        $postId = $this->value('post_id', $existing, $data);
        if (! is_string($postId)) {
            return false;
        }

        return DB::table('community_posts')
            ->where('id', $postId)
            ->where('author_user_id', $userId)
            ->exists();
    }

    private function ownsCommunityReply(string $userId, ?object $existing, array $data): bool
    {
        $therapistId = $this->value('author_therapist_id', $existing, $data);

        return $therapistId === null
            && $this->value('author_user_id', $existing, $data) === $userId;
    }

    private function ownsChatSession(string $userId, ?object $existing, array $data): bool
    {
        if ($this->value('user_id', $existing, $data) !== $userId) {
            return false;
        }

        $horseId = $this->value('horse_id', $existing, $data);

        return ! is_string($horseId) || $horseId === '' || $this->ownsHorse($userId, $horseId);
    }

    private function ownsChatMessage(string $userId, ?object $existing, array $data): bool
    {
        $sessionId = $this->value('session_id', $existing, $data);
        if (! is_string($sessionId)) {
            return false;
        }

        return DB::table('chat_sessions')
            ->where('id', $sessionId)
            ->where('user_id', $userId)
            ->exists();
    }

    private function ownsIntakeBooking(string $userId, ?object $existing, array $data): bool
    {
        if ($this->value('user_id', $existing, $data) !== $userId) {
            return false;
        }

        $horseId = $this->value('horse_id', $existing, $data);

        return ! is_string($horseId) || $horseId === '' || $this->ownsHorse($userId, $horseId);
    }

    private function ownsHorse(string $userId, string $horseId): bool
    {
        return DB::table('horses')
            ->where('id', $horseId)
            ->where('owner_id', $userId)
            ->exists();
    }

    private function row(string $table, string $id): ?object
    {
        return DB::table($table)->where('id', $id)->first();
    }

    private function value(string $key, ?object $existing, array $data): mixed
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        return $existing->{$key} ?? null;
    }

    private function deny(string $table): never
    {
        throw new AuthorizationException("PowerSync write not allowed for table [{$table}].");
    }
}
