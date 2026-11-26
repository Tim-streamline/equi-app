<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A paused protocol also pauses its EquiBoost library access. The time it was paused is added to the
 * three months afterwards, so the owner still gets the full period once the plan is resumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocols', function (Blueprint $table) {
            $table->bigInteger('equiboost_paused_seconds')->default(0);
            $table->timestamp('equiboost_paused_at', 6)->nullable();
        });

        $this->backfillFromAuditLog();

        // Only the database keeps these values, for every way a status can change (admin, bulk, archive, restore).
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION track_equiboost_pause() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    at_now timestamp := timezone('UTC', statement_timestamp());
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.equiboost_paused_seconds := 0;
        NEW.equiboost_paused_at := NULL;
        RETURN NEW;
    END IF;
    NEW.equiboost_paused_seconds := OLD.equiboost_paused_seconds;
    NEW.equiboost_paused_at := OLD.equiboost_paused_at;
    IF OLD.status = 'paused' AND NEW.status <> 'paused' THEN
        IF OLD.equiboost_paused_at IS NOT NULL THEN
            NEW.equiboost_paused_seconds := OLD.equiboost_paused_seconds
                + greatest(0, floor(extract(epoch FROM at_now - OLD.equiboost_paused_at)))::bigint;
        END IF;
        NEW.equiboost_paused_at := NULL;
    ELSIF NEW.status = 'paused' AND OLD.status <> 'paused' AND NEW.equiboost_started_at IS NOT NULL THEN
        NEW.equiboost_paused_at := greatest(at_now, NEW.equiboost_started_at);
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER track_equiboost_pause BEFORE INSERT OR UPDATE ON protocols FOR EACH ROW EXECUTE FUNCTION track_equiboost_pause();
SQL);
        DB::unprepared(file_get_contents(database_path('sql/library-equiboost-pause-access.sql')));
        DB::statement('SELECT refresh_library_access()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS track_equiboost_pause ON protocols; DROP FUNCTION IF EXISTS track_equiboost_pause()');
        DB::unprepared(file_get_contents(database_path('sql/library-equiboost-access.sql')));
        Schema::table('protocols', fn (Blueprint $table) => $table->dropColumn(['equiboost_paused_seconds', 'equiboost_paused_at']));
        DB::statement('SELECT refresh_library_access()');
    }

    /**
     * Earlier pauses count too. Status changes are in the audit log with their time; a protocol that is
     * paused now without a recorded start counts its pause from today.
     */
    private function backfillFromAuditLog(): void
    {
        $now = CarbonImmutable::now('UTC');
        DB::table('protocols')->whereNotNull('equiboost_started_at')->orderBy('id')
            ->each(function ($protocol) use ($now) {
                $started = CarbonImmutable::parse($protocol->equiboost_started_at, 'UTC');
                $seconds = 0;
                $pausedAt = null;
                $changes = DB::table('audit_logs')->where('target_type', 'Protocol')->where('target_id', $protocol->id)
                    ->where('action', 'updated')->orderBy('created_at')->orderBy('id')->get(['before', 'after', 'created_at']);
                foreach ($changes as $change) {
                    $from = json_decode((string) $change->before, true)['status'] ?? null;
                    $to = json_decode((string) $change->after, true)['status'] ?? null;
                    if ($from === null || $to === null || $from === $to) {
                        continue;
                    }
                    $at = CarbonImmutable::parse($change->created_at, 'UTC');
                    if ($to === 'paused') {
                        $pausedAt = $at->max($started);
                    } elseif ($from === 'paused' && $pausedAt) {
                        $seconds += max(0, $pausedAt->diffInSeconds($at, false));
                        $pausedAt = null;
                    }
                }
                if ($protocol->status !== 'paused') {
                    $pausedAt = null;
                } else {
                    $pausedAt ??= $now->max($started);
                }
                DB::table('protocols')->where('id', $protocol->id)->update([
                    'equiboost_paused_seconds' => (int) floor($seconds),
                    'equiboost_paused_at' => $pausedAt?->format('Y-m-d H:i:s.u'),
                ]);
            });
    }
};
