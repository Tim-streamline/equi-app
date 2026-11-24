<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The last plan day of a completed protocol, in its owner's timezone (see ProtocolCompletion).
 * Existing completed plans get a date only when the admin audit trail shows when they were completed;
 * updated_at is not used, because any later edit moves it. Without a reliable record the date stays
 * empty and no new days are inferred for that plan. No history or intake rows are changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Install before the once-daily migration freezes and rewrites historical schedules.
        if (Schema::hasColumn('protocols', 'completed_on')) return;

        Schema::table('protocols', function (Blueprint $table) {
            $table->date('completed_on')->nullable();
            $table->json('closed_periods')->nullable();
        });
        $this->backfill();
    }

    /** Only fills empty dates, so it can run again safely. */
    public function backfill(): void
    {
        $protocols = DB::table('protocols')
            ->leftJoin('horses', 'horses.id', '=', 'protocols.horse_id')
            ->leftJoin('notification_preferences', 'notification_preferences.user_id', '=', 'horses.owner_id')
            ->where(fn ($query) => $query->where('protocols.status', 'completed')
                ->orWhere(fn ($query) => $query->where('protocols.status', 'archived')->where('protocols.archived_previous_status', 'completed')))
            ->whereNull('protocols.completed_on')
            ->get(['protocols.id', 'notification_preferences.timezone']);
        foreach ($protocols as $protocol) {
            $completedAt = $this->auditedCompletion($protocol->id);
            if ($completedAt) {
                DB::table('protocols')->where('id', $protocol->id)->update([
                    'completed_on' => $completedAt->setTimezone(in_array($protocol->timezone, timezone_identifiers_list(), true) ? $protocol->timezone : 'Europe/Amsterdam')->toDateString(),
                ]);
            }
        }
    }

    /** When the current completed state began, if every later status change in the audit trail is an archive/restore of it. */
    private function auditedCompletion(string $id): ?CarbonImmutable
    {
        $changes = DB::table('audit_logs')->where('target_type', 'Protocol')->where('target_id', $id)
            ->select(['before', 'after', 'created_at'])->orderByDesc('created_at')->orderByDesc('id');
        // PostgreSQL wrote this timestamp without an offset using its session timezone.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $changes->selectRaw("extract(epoch from created_at at time zone current_setting('TimeZone')) as audit_epoch");
        }
        foreach ($changes->get() as $change) {
            $before = json_decode($change->before ?? 'null', true) ?: [];
            $after = json_decode($change->after ?? 'null', true) ?: [];
            if (! array_key_exists('status', $after) || ($before['status'] ?? null) === $after['status']) {
                continue;
            }
            $from = $before['status'] ?? null;
            // Archiving a completed plan, or restoring it as completed, keeps the original completion.
            if (($after['status'] === 'archived' && $from === 'completed') || ($after['status'] === 'completed' && $from === 'archived')) {
                continue;
            }

            if ($after['status'] !== 'completed') return null;

            return isset($change->audit_epoch) ? CarbonImmutable::createFromTimestampUTC($change->audit_epoch)
                : CarbonImmutable::parse($change->created_at, 'UTC');
        }

        return null;
    }

    public function down(): void
    {
        Schema::table('protocols', fn (Blueprint $table) => $table->dropColumn(['completed_on', 'closed_periods']));
    }
};
