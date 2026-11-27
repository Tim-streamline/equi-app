<?php

namespace App\Support;

use App\Models\Protocol;
use App\Models\ProtocolSupplementIntake;
use Carbon\CarbonImmutable;

/**
 * Where a started plan stands: per phase and per herb or supplement, counted in plan days
 * (docs/protocol-plan-clock.md).
 *
 * - A plan day is a date from the start on which the plan ran. Dates in paused_periods (paused, completed or
 *   otherwise stopped, and later resumed) and in ProtocolCompletion::closedPeriods() are skipped.
 * - Every phase runs its weeks of 7 plan days. A phase that runs alongside the one before it starts in its
 *   planned week on the plan days. A phase that follows the one before it (it is planned to start after that
 *   one ends) starts the day after that one is done, catching up included.
 * - In a phase, a product (a protocol phase selection) earns credit after each of its scheduled weeks: 7 days
 *   when it was given on at least its "Minimum per week" (min_aantal_per_week) number of days, else the days
 *   it was given. A day counts as given once one of its gifts was ticked off. A short week moves nothing.
 * - A product is done once its credit reaches its "Maximum in fase" (max_aantal_in_fase), and never more than
 *   its scheduled weeks × 7 days. After its last week a phase catches up: only its products still short stay
 *   on the schedule, every day one of them is given adds 1, and the phase is done once all of them are done.
 * - The week shown follows the phase that is furthest along, never beyond its last week. It is extended when
 *   every running phase is catching up.
 * - A date's schedule depends only on the days before it: ticking off today never changes today's schedule.
 *   Days before minimum_gifts_from (plans started before this rule) count as given.
 *
 * Past and current dates follow what happened. Later dates are a projection that assumes every coming day is a
 * plan day on which everything is given. The admin preview ($ideal) projects from the start that way.
 */
class ProtocolClock
{
    /** @var array<string, int> Week shown per plan date: up to today as it happened, later projected. */
    private array $weeks = [];

    /** @var array<string, array<int, string>> Per plan date the products on the schedule. */
    private array $active = [];

    /** @var array<string, string> First date of each phase, past and projected. */
    private array $phaseStarts = [];

    /** @var array<string, string> First date after each phase, past and projected. */
    private array $phaseEnds = [];

    /** Plan days that have ended. */
    private int $elapsed = 0;

    /** @var array<string, int> Per running phase the plan day it started on (0-based). */
    private array $started = [];

    /** @var array<string, true> Phases whose last week is behind them and that are catching up. */
    private array $catchingUp = [];

    /** @var array<string, true> */
    private array $phaseDone = [];

    /** @var array<string, int> Credit in days per product. */
    private array $credit = [];

    /** @var array<string, int> Days given in the product's current week. */
    private array $weekGiven = [];

    /** @var array<string, true> */
    private array $done = [];

    private int $planDays = 0;

    /** First date after the plan, once known (past or projected). */
    private ?string $planEnd = null;

    private function __construct(
        public readonly ?CarbonImmutable $start,
        public readonly CarbonImmutable $today,
        public readonly int $totalWeeks,
        /**
         * In plan order: first and last planned week, the phase it follows (null: it runs alongside), and its
         * products with their planned weeks, minimum and target.
         *
         * @var array<string, array{first: int, last: int, follows: ?string, products: array<string, array{name: string, weeks: array<int, int>, required: int, target: int}>}>
         */
        private readonly array $phases,
    ) {}

    public static function for(Protocol $protocol, CarbonImmutable $now, bool $ideal = false): self
    {
        $protocol->loadMissing('phases.weeks', 'phases.supplements.weeks');
        $today = $now->startOfDay();
        $start = $protocol->started_at ? CarbonImmutable::parse($protocol->started_at->toDateString(), $now->timezone)->startOfDay() : null;
        $clock = new self($start, $today, $protocol->scheduledWeeks(), self::phases($protocol));
        if (! $start) {
            return $clock;
        }
        $completion = app(ProtocolCompletion::class);
        // A closure without a known first day cannot be placed on the clock.
        $closed = array_filter($completion->closedPeriods($protocol), fn ($period) => $period['after'] !== null);
        $skipped = $ideal ? [] : array_merge($protocol->paused_periods ?? [], $closed);
        $rulesFrom = $ideal ? '9999-12-31' : ($protocol->minimum_gifts_from?->toDateString() ?? '0000-01-01');
        $ticks = $ideal || $today->lt($start) ? [] : self::ticks($protocol, $start, $today);

        for ($day = $start; $day->lte($today); $day = $day->addDay()) {
            $date = $day->toDateString();
            if ($completion->containsDate($skipped, $date)) {
                continue;
            }
            $clock->begin($date);
            $clock->planDays++;
            if ($day->lt($today)) {
                $clock->advance($date, $date < $rulesFrom ? null : ($ticks[$date] ?? []));
            }
        }
        $clock->project();

        return $clock;
    }

    /** Starts the phases due on this plan day and records what is on its schedule. */
    private function begin(string $date): void
    {
        foreach ($this->phases as $id => $phase) {
            if (isset($this->started[$id]) || isset($this->phaseDone[$id])) {
                continue;
            }
            $due = $phase['follows'] !== null
                ? isset($this->phaseDone[$phase['follows']])
                : $this->elapsed >= 7 * ($phase['first'] - 1);
            if ($due) {
                $this->started[$id] = $this->elapsed;
                $this->phaseStarts[$id] ??= $date;
            }
        }
        $this->weeks[$date] ??= $this->shownWeek();
        $this->active[$date] ??= $this->scheduled();
    }

    /** Week of a running phase, as a plan week number; its last week while it catches up. */
    private function phaseWeek(string $id): int
    {
        $phase = $this->phases[$id];

        return isset($this->catchingUp[$id]) ? $phase['last']
            : min($phase['last'], $phase['first'] + intdiv($this->elapsed - $this->started[$id], 7));
    }

    private function shownWeek(): int
    {
        $weeks = array_map(fn ($id) => $this->phaseWeek($id), array_keys($this->started));
        if ($weeks) {
            return max($weeks);
        }
        if ($this->finished()) {
            return $this->totalWeeks + 1;
        }

        return min(max(1, $this->totalWeeks), intdiv($this->elapsed, 7) + 1);
    }

    /** @return array<int, string> The products on the schedule while the clock stands where it does. */
    private function scheduled(): array
    {
        $ids = [];
        foreach (array_keys($this->started) as $phaseId) {
            $week = $this->phaseWeek($phaseId);
            foreach ($this->phases[$phaseId]['products'] as $id => $product) {
                // Catching up happens only at the end of a phase, for the products still short.
                if (! isset($this->done[$id]) && (isset($this->catchingUp[$phaseId]) || in_array($week, $product['weeks'], true))) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Ends a plan day.
     *
     * @param  array<string, true>|null  $ticked  Products ticked off that day; null for all of them.
     */
    private function advance(string $date, ?array $ticked): void
    {
        $next = CarbonImmutable::parse($date)->addDay()->toDateString();
        foreach (array_keys($this->started) as $phaseId) {
            $phase = $this->phases[$phaseId];
            $week = $this->phaseWeek($phaseId);
            $catchingUp = isset($this->catchingUp[$phaseId]);
            foreach ($phase['products'] as $id => $product) {
                if (isset($this->done[$id])) {
                    continue;
                }
                $given = $ticked === null || isset($ticked[$id]);
                if ($catchingUp) {
                    if ($given) {
                        $this->credit[$id] = ($this->credit[$id] ?? 0) + 1;
                        $this->finishIfDone($phaseId, $id);
                    }
                } elseif (in_array($week, $product['weeks'], true) && $given) {
                    $this->weekGiven[$id] = ($this->weekGiven[$id] ?? 0) + 1;
                }
            }
            $day = $this->elapsed - $this->started[$phaseId] + 1;
            if (! $catchingUp && $day % 7 === 0) {
                foreach ($phase['products'] as $id => $product) {
                    if (! isset($this->done[$id]) && in_array($week, $product['weeks'], true)) {
                        $given = $this->weekGiven[$id] ?? 0;
                        $this->credit[$id] = ($this->credit[$id] ?? 0) + ($given >= $product['required'] ? 7 : $given);
                        $this->weekGiven[$id] = 0;
                        $this->finishIfDone($phaseId, $id);
                    }
                }
                if ($day >= 7 * ($phase['last'] - $phase['first'] + 1)) {
                    $this->catchingUp[$phaseId] = true;
                }
            }
            if (isset($this->catchingUp[$phaseId]) && $this->allDone($phaseId)) {
                unset($this->started[$phaseId], $this->catchingUp[$phaseId]);
                $this->phaseDone[$phaseId] = true;
                $this->phaseEnds[$phaseId] ??= $next;
            }
        }
        $this->elapsed++;
        if ($this->planEnd === null && $this->finished()) {
            $this->planEnd = $next;
        }
    }

    private function finishIfDone(string $phaseId, string $id): void
    {
        if (($this->credit[$id] ?? 0) >= $this->phases[$phaseId]['products'][$id]['target']) {
            $this->done[$id] = true;
        }
    }

    private function allDone(string $phaseId): bool
    {
        foreach (array_keys($this->phases[$phaseId]['products']) as $id) {
            if (! isset($this->done[$id])) {
                return false;
            }
        }

        return true;
    }

    /** Coming days, assuming each one from the first open day on is a plan day on which everything is given. */
    private function project(): void
    {
        $clone = clone $this;
        $todayIsPlanDay = isset($this->weeks[$this->today->toDateString()]);
        $day = ($todayIsPlanDay ? $this->today : $this->today->addDay())->max($this->start);
        $limit = 7 * ($this->totalWeeks + 2) + array_sum(array_map(fn ($phase) => array_sum(array_column($phase['products'], 'target')), $this->phases));
        for ($guard = 0; ! $clone->finished() && $guard < $limit; $guard++, $day = $day->addDay()) {
            $date = $day->toDateString();
            $clone->begin($date);
            $clone->advance($date, null);
        }
        $clone->weeks[$day->toDateString()] ??= $clone->shownWeek();
        $this->phaseStarts = $clone->phaseStarts;
        $this->phaseEnds = $clone->phaseEnds;
        $this->planEnd = $clone->planEnd;
        $this->weeks += $clone->weeks;
        $this->active += $clone->active;
    }

    /** @return array<string, array{first: int, last: int, follows: ?string, products: array<string, array{name: string, weeks: array<int, int>, required: int, target: int}>}> */
    private static function phases(Protocol $protocol): array
    {
        $phases = [];
        $previous = null;
        foreach ($protocol->phases as $phase) {
            $weeks = $phase->weeks->pluck('protocol_week_number', 'id')->filter();
            if ($weeks->isEmpty() || ! $phase->id) {
                continue;
            }
            $first = (int) $weeks->min();
            $last = (int) $weeks->max();
            $products = [];
            foreach ($phase->supplements as $selection) {
                $numbers = $selection->weeks->map(fn ($link) => (int) $weeks->get($link->protocol_phase_week_id))
                    ->filter()->unique()->sort()->values()->all();
                if (! $numbers || ! $selection->id) {
                    continue;
                }
                $planned = 7 * count($numbers);
                $max = (int) $selection->max_aantal_in_fase;
                $products[$selection->id] = ['name' => (string) $selection->name, 'weeks' => $numbers,
                    'required' => min(7, max(0, (int) $selection->min_aantal_per_week)),
                    'target' => $max > 0 ? min($max, $planned) : $planned];
            }
            // Planned to start after the phase before it ends: it follows that phase and waits for it.
            $follows = $previous !== null && $first > $phases[$previous]['last'] ? $previous : null;
            $phases[$phase->id] = ['first' => $first, 'last' => $last, 'follows' => $follows, 'products' => $products];
            $previous = $phase->id;
        }

        return $phases;
    }

    /** @return array<string, array<string, true>> Per date the products with at least one gift ticked off. */
    private static function ticks(Protocol $protocol, CarbonImmutable $start, CarbonImmutable $today): array
    {
        $ticks = [];
        ProtocolSupplementIntake::query()->where('done', true)
            ->whereIn('protocol_phase_supplement_id', $protocol->phases->flatMap->supplements->pluck('id')->filter()->all())
            ->whereBetween('date', [$start->toDateString(), $today->toDateString()])
            ->get(['protocol_phase_supplement_id', 'date'])
            ->each(function ($row) use (&$ticks) {
                $ticks[substr((string) $row->getRawOriginal('date'), 0, 10)][$row->protocol_phase_supplement_id] = true;
            });

        return $ticks;
    }

    public function started(): bool
    {
        return $this->start !== null && $this->today->gte($this->start);
    }

    /** The week shown on a date: past and today as they happened, later dates projected; null when no plan day. */
    public function weekOn(string $date): ?int
    {
        if (! $this->start || $date < $this->start->toDateString()) {
            return null;
        }
        if ($date <= $this->today->toDateString() || isset($this->weeks[$date])) {
            return $this->weeks[$date] ?? null;
        }
        // After the projection ends, the plan is over.
        return $this->totalWeeks + 1;
    }

    /**
     * Ids of the products on the schedule on a date: past and today as they happened, later dates projected.
     * Null when the date is no plan day (paused, closed or before the start).
     *
     * @return array<int, string>|null
     */
    public function activeOn(string $date): ?array
    {
        if (! $this->start || $date < $this->start->toDateString()) {
            return null;
        }
        if ($date <= $this->today->toDateString()) {
            return $this->active[$date] ?? null;
        }

        return $this->active[$date] ?? [];
    }

    /** @return array<int, string> The products on the schedule today, or while paused those the plan resumes with. */
    public function activeNow(): array
    {
        return $this->started() ? ($this->active[$this->today->toDateString()] ?? $this->scheduled()) : [];
    }

    /** The week shown now (also while paused: the week it resumes in). 0 before the start. */
    public function currentWeek(): int
    {
        return $this->started() ? ($this->weeks[$this->today->toDateString()] ?? $this->shownWeek()) : 0;
    }

    /** Every running phase is catching up: the week shown is extended. */
    public function extended(): bool
    {
        return $this->started() && $this->started !== [] && count($this->catchingUp) === count($this->started);
    }

    /** Plan days so far, today included when it is one. */
    public function planDays(): int
    {
        return $this->planDays;
    }

    /** Weekly updates come every 7 plan days, also while catching up: the number of the current period. */
    public function updateWeek(): int
    {
        return $this->planDays > 0 ? intdiv($this->planDays - 1, 7) + 1 : 0;
    }

    /** Plan day within the current weekly-update period (1-7). */
    public function updateDay(): int
    {
        return $this->planDays > 0 ? ($this->planDays - 1) % 7 + 1 : 0;
    }

    /** Every phase is done and the planned weeks are behind the plan. */
    public function finished(): bool
    {
        return $this->start !== null && $this->totalWeeks > 0 && count($this->phaseDone) === count($this->phases)
            && $this->elapsed >= 7 * $this->totalWeeks;
    }

    /** First date of a phase (past or projected), Y-m-d. */
    public function phaseStart(string $phaseId): ?string
    {
        return $this->phaseStarts[$phaseId] ?? null;
    }

    /** Week within a running phase now (1-based, its last week while it catches up); null when it does not run. */
    public function phaseWeekNow(string $phaseId): ?int
    {
        return $this->started() && isset($this->started[$phaseId]) ? $this->phaseWeek($phaseId) - $this->phases[$phaseId]['first'] + 1 : null;
    }

    /** First date after a phase (past or projected), Y-m-d. */
    public function phaseEnd(string $phaseId): ?string
    {
        return $this->phaseEnds[$phaseId] ?? null;
    }

    /** First date after the plan (past or projected), Y-m-d. */
    public function planEnd(): ?string
    {
        return $this->planEnd;
    }

    /**
     * Products catching up today at the end of their phase, with the days each still has to be given (today's
     * tick not counted).
     *
     * @return array<int, array{id: string, name: string, remaining: int}>
     */
    public function extensions(): array
    {
        $items = [];
        foreach ($this->started() ? array_keys($this->catchingUp) : [] as $phaseId) {
            foreach ($this->phases[$phaseId]['products'] as $id => $product) {
                if (! isset($this->done[$id])) {
                    $items[] = ['id' => $id, 'name' => $product['name'], 'remaining' => max(1, $product['target'] - ($this->credit[$id] ?? 0))];
                }
            }
        }

        return $items;
    }
}
