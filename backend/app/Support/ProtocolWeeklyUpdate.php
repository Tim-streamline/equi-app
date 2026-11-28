<?php

namespace App\Support;

use App\Models\Protocol;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The owner's weekly update ("weekupdate") of a running plan.
 *
 * - It opens on the update day of a protocol week (customer_settings.weekly_update_day, default day 7)
 *   and stays open until it is filled in, also after the next week has started.
 * - Only the latest week counts: once a later week's update day has passed, an older unfilled week lapses.
 * - A week whose update day fell while the plan was paused lapses.
 * - The last week with an update is three weeks before the end of the plan; after it the evaluation follows.
 */
class ProtocolWeeklyUpdate
{
    /** Weeks at the end of a plan without a weekly update. */
    public const WEEKS_WITHOUT_UPDATE_AT_END = 3;

    /**
     * The week number whose update is open now, or null.
     *
     * @param  array{start: ?CarbonImmutable, day: int, totalWeeks: int, running: bool}  $progress  HorseDashboard::progress()
     */
    public function openWeek(Protocol $protocol, array $progress, CarbonImmutable $now): ?int
    {
        if ($protocol->status !== 'active' || ! $progress['running'] || ! $progress['start'] || $progress['day'] < 1) {
            return null;
        }
        $updateDay = (int) ($protocol->customer_settings['weekly_update_day'] ?? 7);
        $week = (int) ceil($progress['day'] / 7);
        $dayOfWeek = ($progress['day'] - 1) % 7 + 1;
        // The latest week whose update day has been reached, but never past the last week with an update.
        $latest = min($dayOfWeek >= $updateDay ? $week : $week - 1, $progress['totalWeeks'] - self::WEEKS_WITHOUT_UPDATE_AT_END);
        if ($latest < 1) {
            return null;
        }
        $date = $progress['start']->addDays(($latest - 1) * 7 + $updateDay - 1)->toDateString();
        if (! $this->activeOn($protocol, $date)) {
            return null;
        }

        return DB::table('protocol_weekly_updates')->where('protocol_id', $protocol->id)->where('week_number', $latest)->exists()
            ? null : $latest;
    }

    /**
     * Whether the plan was active on a date. The day of a pause still counts as active.
     *
     * First from the pause history, which is never pruned: the last update day (three weeks before the end)
     * can stay open for weeks. Before that history begins, from the recorded active periods: those are pruned
     * to the last week at every status change, which is enough for an update day less than a week ago.
     * A plan without recorded periods has never been paused.
     */
    private function activeOn(Protocol $protocol, string $date): bool
    {
        $paused = app(ProtocolCompletion::class)->pausedOn($protocol, $date);
        if ($paused !== null) {
            return ! $paused;
        }
        $periods = $protocol->intake_active_periods ?? [];
        if ($periods === []) {
            return true;
        }
        foreach ($periods as $period) {
            if ($period['from'] <= $date && ($period['until'] === null || $date <= $period['until'])) {
                return true;
            }
        }

        return false;
    }
}
