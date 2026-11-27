<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The plan clock (App\Support\ProtocolClock) counts plan days instead of calendar days.
 *
 * - paused_periods: when a plan did not run, as {after, before} dates (exclusive), like closed_periods.
 *   Earlier pauses come from the audit log, so plans paused before now go back to where they were paused.
 * - minimum_gifts_from: plans that already run get the "Minimum per week" rule from today. Their earlier days
 *   count as given, so they do not move back for gifts that were never ticked off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocols', function (Blueprint $table) {
            $table->json('paused_periods')->nullable();
            $table->date('minimum_gifts_from')->nullable();
        });

        DB::table('protocols')->whereNotNull('started_at')->orderBy('id')->each(function ($protocol) {
            $timezone = DB::table('horses')->join('notification_preferences', 'notification_preferences.user_id', '=', 'horses.owner_id')
                ->where('horses.id', $protocol->horse_id)->value('notification_preferences.timezone') ?: 'Europe/Amsterdam';
            $today = CarbonImmutable::now($timezone)->toDateString();
            $started = substr((string) $protocol->started_at, 0, 10);
            DB::table('protocols')->where('id', $protocol->id)->update([
                'minimum_gifts_from' => max($today, $started),
                'paused_periods' => json_encode($this->pausedPeriods($protocol, $timezone, $today)),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('protocols', fn (Blueprint $table) => $table->dropColumn(['paused_periods', 'minimum_gifts_from']));
    }

    /**
     * Leaving 'active' opens a period on that day, returning to it closes one. A plan that is not active now
     * without a recorded moment it stopped is taken as stopped today: no day is guessed away.
     */
    private function pausedPeriods(object $protocol, string $timezone, string $today): array
    {
        $periods = [];
        $open = null;
        $changes = DB::table('audit_logs')->where('target_type', 'Protocol')->where('target_id', $protocol->id)
            ->where('action', 'updated')->orderBy('created_at')->orderBy('id')->get(['before', 'after', 'created_at']);
        foreach ($changes as $change) {
            $from = json_decode((string) $change->before, true)['status'] ?? null;
            $to = json_decode((string) $change->after, true)['status'] ?? null;
            if ($from === null || $to === null || $from === $to) {
                continue;
            }
            $date = CarbonImmutable::parse($change->created_at, 'UTC')->setTimezone($timezone)->toDateString();
            if ($from === 'active' && $open === null) {
                $open = $date;
            } elseif ($to === 'active' && $open !== null) {
                $periods[] = ['after' => $open, 'before' => $date];
                $open = null;
            }
        }
        if ($protocol->status !== 'active') {
            $periods[] = ['after' => $open ?? $today, 'before' => null];
        }

        // Only periods that skip at least one whole day.
        return array_values(array_filter($periods, fn ($period) => $period['before'] === null
            || CarbonImmutable::parse($period['after'])->addDay()->toDateString() < $period['before']));
    }
};
