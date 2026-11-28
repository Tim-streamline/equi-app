<?php

namespace App\Support;

use App\Models\Protocol;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * The last plan day of a completed protocol (`protocols.completed_on`), a date in the owner's timezone.
 * Days after it are not part of the plan: they are never frozen as missed, shown in the calendar or
 * counted as progress. Doses recorded on or before it stay as recorded.
 *
 * Set on every status transition through Protocol::save (individual, bulk, archive, restore and reopen):
 * - becoming completed from a running state records today in the owner's timezone;
 * - archiving keeps the date, and restoring an archived completed plan keeps it;
 * - reopening (draft, active or paused) clears it, because the plan runs again.
 *
 * A completed plan without a date (completed before this column, without an audit record of when) is
 * treated as unknown: no new days are frozen or shown as missed for it, and nothing is guessed.
 */
class ProtocolCompletion
{
    public function apply(Protocol $protocol): void
    {
        // Older migrations save protocols before this column exists.
        if (! $protocol->isDirty('status') || ! Schema::hasColumn('protocols', 'completed_on')) {
            return;
        }
        $from = $protocol->exists ? $protocol->getRawOriginal('status') : null;
        $to = $protocol->status;
        if (($from === 'active' || $to === 'active') && Schema::hasColumn('protocols', 'intake_active_periods')) {
            $this->recordActivePeriod($protocol);
        }
        if (Schema::hasColumn('protocols', 'pause_history')) {
            $this->recordPause($protocol, $from, $to);
        }
        if ($to === 'archived') {
            return;
        }
        if ($to !== 'completed') {
            if ($this->endedAsCompleted($from, $protocol->getRawOriginal('archived_previous_status'))) {
                $after = $protocol->completed_on?->toDateString();
                $resume = CarbonImmutable::today($this->timezone($protocol));
                if ($after === null || $after < $resume->subDay()->toDateString()) {
                    $periods = $protocol->closed_periods ?? [];
                    $periods[] = ['after' => $after, 'before' => $resume->toDateString()];
                    $protocol->closed_periods = $periods;
                }
            }
            $protocol->completed_on = null;

            return;
        }
        // Restoring an archived completed plan keeps its original last day.
        if ($from === 'archived' || $protocol->isDirty('completed_on')) {
            return;
        }
        $protocol->completed_on = CarbonImmutable::now($this->timezone($protocol))->toDateString();
    }

    /** Delayed uploads to a paused plan are allowed only for dates in its recent active periods. */
    private function recordActivePeriod(Protocol $protocol): void
    {
        $now = CarbonImmutable::now($this->timezone($protocol));
        $today = $now->toDateString();
        $oldest = $now->subDays(7)->toDateString();
        $periods = $protocol->intake_active_periods ?? [];
        $last = array_key_last($periods);
        if ($protocol->status === 'active') {
            // Date-only ticks cannot distinguish the two sides of a pause and resume on the same day.
            $from = $last !== null && $periods[$last]['until'] === $today ? $now->addDay()->toDateString() : $today;
            $periods[] = ['from' => $from, 'until' => null];
        } else {
            if ($protocol->status === 'paused') {
                app(ProtocolDayHistory::class)->preserve($protocol, $now);
            }
            if ($last !== null && $periods[$last]['until'] === null) {
                $periods[$last]['until'] = $today;
            } elseif ($lastSaved = $protocol->getOriginal('updated_at')) {
                // An active plan predating this column was already active at its last persisted edit.
                // Earlier dates may belong to an unrecorded pause.
                $periods[] = ['from' => $lastSaved->copy()->setTimezone($now->timezone)->toDateString(), 'until' => $today];
            }
        }
        $protocol->intake_active_periods = array_values(array_filter($periods, fn ($period) =>
            $period['until'] === null || $period['until'] > $oldest));
    }

    /**
     * Every pause of the plan as {from: day it was paused, until: day it was resumed or null}, never pruned
     * (unlike intake_active_periods). `since` is the first status change after this was introduced:
     * the history is complete from that day on. The weekly update uses it (ProtocolWeeklyUpdate).
     */
    private function recordPause(Protocol $protocol, ?string $from, string $to): void
    {
        $today = CarbonImmutable::today($this->timezone($protocol))->toDateString();
        $history = $protocol->pause_history ?? ['since' => $today, 'pauses' => []];
        $last = array_key_last($history['pauses']);
        if ($to === 'paused' && $from !== 'paused') {
            $history['pauses'][] = ['from' => $today, 'until' => null];
        } elseif ($from === 'paused' && $to !== 'paused' && $last !== null && $history['pauses'][$last]['until'] === null) {
            $history['pauses'][$last]['until'] = $today;
        }
        $protocol->pause_history = $history;
    }

    /**
     * Whether the plan was paused on a date, from pause_history: after the day of pausing and before the day of
     * resuming (both of those days count as active). Null when the history does not reach back to that date.
     */
    public function pausedOn(Protocol $protocol, string $date): ?bool
    {
        $history = $protocol->pause_history;
        if (! $history || $date < $history['since']) {
            return null;
        }
        foreach ($history['pauses'] as $pause) {
            if ($pause['from'] < $date && ($pause['until'] === null || $date < $pause['until'])) {
                return true;
            }
        }

        return false;
    }

    public function beforeKnownPause(Protocol $protocol, string $date): bool
    {
        if ($protocol->status === 'paused') {
            foreach ($protocol->intake_active_periods ?? [] as $period) {
                if ($period['until'] !== null && $period['from'] <= $date && $date < $period['until']) {
                    return true;
                }
            }
        }

        return false;
    }

    public function timezone(Protocol $protocol): string
    {
        return $protocol->horse?->owner?->notificationPreferences?->timezone ?: 'Europe/Amsterdam';
    }

    /** The last date (Y-m-d) that belongs to a completed plan, or null when it is not completed or the day is unknown. */
    public function lastPlanDay(Protocol $protocol): ?string
    {
        return $this->endedAsCompleted($protocol->status, $protocol->archived_previous_status)
            ? $protocol->completed_on?->toDateString() : null;
    }

    /** A completed plan for which no last day is known: only already frozen days are shown. */
    public function unknownEnd(Protocol $protocol): bool
    {
        return $this->endedAsCompleted($protocol->status, $protocol->archived_previous_status) && ! $protocol->completed_on;
    }

    /** Open date intervals for which a schedule cannot be inferred, including previous closures. */
    public function closedPeriods(Protocol $protocol): array
    {
        $periods = $protocol->closed_periods ?? [];
        if ($this->endedAsCompleted($protocol->status, $protocol->archived_previous_status)) {
            $periods[] = ['after' => $protocol->completed_on?->toDateString(), 'before' => null];
        }

        return $periods;
    }

    /** Completed, or archived from completed. */
    private function endedAsCompleted(?string $status, ?string $archivedPreviousStatus): bool
    {
        return $status === 'completed' || ($status === 'archived' && $archivedPreviousStatus === 'completed');
    }

    public function containsDate(array $periods, string $date): bool
    {
        foreach ($periods as $period) {
            if (($period['after'] === null || $period['after'] < $date) && ($period['before'] === null || $date < $period['before'])) {
                return true;
            }
        }

        return false;
    }
}
