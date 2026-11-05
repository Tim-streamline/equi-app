<?php

namespace App\Support;

use App\Models\Protocol;
use Carbon\CarbonImmutable;

/** Persist the same phase states and labels for editor saves and owner starts. */
class ProtocolTiming
{
    public function synchronize(Protocol $protocol, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $protocol->load('phases.weeks', 'horse');
        $previousWeekStart = null;
        $previousWeekEnd = null;
        $latestWeekEnd = 0;

        foreach ($protocol->phases as $phaseIndex => $phase) {
            $phaseWeekStart = match (true) {
                $phaseIndex === 0 => 1,
                $phase->start_after_previous_phase_weeks !== null && $previousWeekStart !== null => $previousWeekStart + $phase->start_after_previous_phase_weeks,
                $previousWeekEnd !== null => $previousWeekEnd + 1,
                default => $latestWeekEnd + 1,
            };

            foreach ($phase->weeks as $weekIndex => $week) {
                $week->update(['protocol_week_number' => $phaseWeekStart + $weekIndex]);
            }

            $previousWeekStart = $phase->weeks->isNotEmpty() ? $phaseWeekStart : null;
            $previousWeekEnd = $phase->weeks->isNotEmpty()
                ? $phaseWeekStart + $phase->weeks->count() - 1
                : null;
            $latestWeekEnd = max($latestWeekEnd, $previousWeekEnd ?? 0);
        }

        $totalWeeks = $latestWeekEnd;
        $currentWeek = $this->currentWeek($protocol, $totalWeeks, $now);

        foreach ($protocol->phases as $phase) {
            $weekStart = $phase->weeks->first()?->protocol_week_number;
            $weekEnd = $phase->weeks->last()?->protocol_week_number;
            $state = match (true) {
                $weekStart === null => 'upcoming',
                $protocol->status === 'completed' => 'done',
                $weekEnd < $currentWeek => 'done',
                $weekStart <= $currentWeek && $weekEnd >= $currentWeek => 'active',
                default => 'upcoming',
            };
            $chip = match ($state) {
                'done' => 'Afgerond',
                'active' => "Actief · wk {$weekStart}–{$weekEnd}",
                default => $weekStart ? "Vanaf wk {$weekStart}" : 'Geen weken',
            };

            $phase->update([
                'state' => $state,
                'week_start' => $weekStart,
                'week_end' => $weekEnd,
                'chip_label' => $chip,
            ]);
        }

        $activePhase = $protocol->phases->firstWhere('state', 'active');
        $analysisParts = collect([$protocol->horse?->breed])->filter()->values();

        $protocol->update([
            'total_weeks' => $totalWeeks ?: null,
            'current_week' => $totalWeeks ? $currentWeek : null,
            'subtitle_analyse' => $analysisParts->join(' · ') ?: null,
            'subtitle_protocol' => $totalWeeks
                ? "Week {$currentWeek} van {$totalWeeks}".($activePhase ? " · {$activePhase->title} actief" : '')
                : 'Planning nog niet compleet',
            'subtitle_calendar' => $protocol->started_at?->locale('nl')->translatedFormat('F Y'),
        ]);
    }

    private function currentWeek(Protocol $protocol, int $totalWeeks, CarbonImmutable $now): int
    {
        if ($totalWeeks < 1) {
            return 1;
        }

        if ($protocol->status === 'completed') {
            return $totalWeeks;
        }

        if (! $protocol->started_at) {
            return 0;
        }

        if ($protocol->status === 'paused') {
            return max(1, min($totalWeeks, (int) ($protocol->current_week ?: 1)));
        }

        $days = CarbonImmutable::parse($protocol->started_at->toDateString(), $now->timezone)->startOfDay()->diffInDays($now->startOfDay(), false);

        return max(1, min($totalWeeks, (int) floor(max(0, $days) / 7) + 1));
    }

}
