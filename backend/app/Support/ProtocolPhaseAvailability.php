<?php

namespace App\Support;

use App\Models\Protocol;
use App\Models\ProtocolPhase;
use Carbon\CarbonImmutable;

/** Phase boundaries on the plan clock (ProtocolClock), shared by the dashboard and scheduled reminders. */
class ProtocolPhaseAvailability
{
    public function forPhase(Protocol $protocol, ProtocolPhase $phase, CarbonImmutable $now, ?ProtocolClock $clock = null): array
    {
        $clock ??= ProtocolClock::for($protocol, $now);
        $weeks = $phase->weeks->pluck('protocol_week_number');
        $first = (int) ($weeks->min() ?? $phase->week_start ?? 0);
        $last = (int) ($weeks->max() ?? $phase->week_end ?? 0);
        // Pauses and catching up move the dates; coming dates assume everything is given.
        $planned = $protocol->started_at && $first > 0 && $last >= $first;
        $startDate = $planned && $phase->id ? $clock->phaseStart($phase->id) : null;
        $endDate = $startDate ? $clock->phaseEnd($phase->id) : null;
        // A phase the clock does not know (not saved with the plan): its calendar weeks from the start.
        $start = $startDate ? CarbonImmutable::parse($startDate, $now->timezone)->startOfDay()
            : ($planned ? CarbonImmutable::parse($protocol->started_at->toDateString(), $now->timezone)->startOfDay()->addWeeks($first - 1) : null);
        $end = $endDate ? CarbonImmutable::parse($endDate, $now->timezone)->startOfDay() : $start?->addWeeks($last - $first + 1);
        $available = $start?->subDays(7);
        $state = ! $start || $now->lt($available) ? 'locked'
            : ($now->lt($start) ? 'preview' : ($now->lt($end) ? 'active' : 'done'));
        $completed = $protocol->status === 'completed';
        if ($completed && $state === 'active') $state = 'done';

        $duration = $first > 0 ? max(0, $last - $first + 1) : 0;
        $currentWeek = $state === 'active' ? max(1, min($duration, ($phase->id ? $clock->phaseWeekNow($phase->id) : null) ?? (int) floor($start->diffInDays($now->startOfDay()) / 7) + 1)) : null;

        return [
            'durationWeeks' => $duration, 'currentPhaseWeek' => $currentWeek,
            'durationLabel' => $duration ? 'Duur: '.$duration.($duration === 1 ? ' week' : ' weken') : 'Nog niet ingepland',
            'weekStart' => $first, 'weekEnd' => $last,
            'weekLabel' => ! $protocol->started_at ? 'Nog niet gestart' : ($first ? 'Week '.$first.($last > $first ? ' t/m '.$last : '') : 'Nog niet ingepland'),
            'state' => $state, 'accessible' => $state !== 'locked',
            'startsAt' => $start?->toIso8601String(), 'endsAt' => $end?->toIso8601String(), 'availableAt' => $available?->toIso8601String(),
            'statusLabel' => $completed ? 'Herstelplan afgerond' : match ($state) {
                'active' => 'Actief · week '.$currentWeek.' van '.$duration,
                'preview' => 'Start volgende week',
                'done' => 'Afgerond',
                default => ! $protocol->started_at ? 'Nog niet gestart' : ($first ? 'Start in protocolweek '.$first : 'Nog niet ingepland'),
            },
        ];
    }
}
