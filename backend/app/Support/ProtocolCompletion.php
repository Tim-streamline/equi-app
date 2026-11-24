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
        if ($to === 'archived') {
            return;
        }
        if ($to !== 'completed') {
            if ($from === 'completed' || ($from === 'archived' && $protocol->getRawOriginal('archived_previous_status') === 'completed')) {
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

    public function timezone(Protocol $protocol): string
    {
        return $protocol->horse?->owner?->notificationPreferences?->timezone ?: 'Europe/Amsterdam';
    }

    /** The last date (Y-m-d) that belongs to a completed plan, or null when it is not completed or the day is unknown. */
    public function lastPlanDay(Protocol $protocol): ?string
    {
        if (! in_array($protocol->status, ['completed', 'archived'], true)) {
            return null;
        }
        if ($protocol->status === 'archived' && $protocol->archived_previous_status !== 'completed') {
            return null;
        }

        return $protocol->completed_on?->toDateString();
    }

    /** A completed plan for which no last day is known: only already frozen days are shown. */
    public function unknownEnd(Protocol $protocol): bool
    {
        return ($protocol->status === 'completed' || ($protocol->status === 'archived' && $protocol->archived_previous_status === 'completed'))
            && ! $protocol->completed_on;
    }

    /** Open date intervals for which a schedule cannot be inferred, including previous closures. */
    public function closedPeriods(Protocol $protocol): array
    {
        $periods = $protocol->closed_periods ?? [];
        $last = $this->lastPlanDay($protocol);
        if ($last !== null || $this->unknownEnd($protocol)) {
            $periods[] = ['after' => $last, 'before' => null];
        }

        return $periods;
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
