<?php

namespace App\Support;

use App\Models\Protocol;
use App\Models\ProtocolPhase;
use Carbon\CarbonImmutable;

/** Calendar-day boundaries, shared by the dashboard and scheduled reminders. */
class ProtocolPhaseAvailability
{
    public function forPhase(Protocol $protocol, ProtocolPhase $phase, CarbonImmutable $now): array
    {
        $weeks = $phase->weeks->pluck('protocol_week_number');
        $first = (int) ($weeks->min() ?? $phase->week_start ?? 0);
        $last = (int) ($weeks->max() ?? $phase->week_end ?? 0);
        $start = $protocol->started_at && $first > 0 && $last >= $first
            ? CarbonImmutable::parse($protocol->started_at->toDateString(), $now->timezone)->startOfDay()->addWeeks($first - 1)
            : null;
        $end = $start?->addWeeks($last - $first + 1);
        $available = $start?->subDays(7);
        $state = ! $start || $now->lt($available) ? 'locked'
            : ($now->lt($start) ? 'preview' : ($now->lt($end) ? 'active' : 'done'));

        return [
            'weekStart' => $first, 'weekEnd' => $last,
            'weekLabel' => $first ? 'Week '.$first.($last > $first ? ' t/m '.$last : '') : 'Nog niet ingepland',
            'state' => $state, 'accessible' => $state !== 'locked',
            'startsAt' => $start?->toIso8601String(), 'endsAt' => $end?->toIso8601String(), 'availableAt' => $available?->toIso8601String(),
            'statusLabel' => match ($state) {
                'active' => 'Actief · wk '.$first.'–'.$last,
                'preview' => 'Start volgende week',
                'done' => 'Afgerond',
                default => $first ? 'Vanaf wk '.$first : 'Nog niet ingepland',
            },
        ];
    }
}
