<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\LibraryItem;
use App\Models\Plan;
use App\Models\PlusPage;
use App\Models\Protocol;
use App\Models\SeasonalTip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HorseDashboard
{
    /** The fixed drinking-water article the Voeding tab links to, matched by its title's slug. */
    private const WATER_ARTICLE_SLUG = 'drinkwater-onder-de-loep-wat-krijgt-je-paard-binnen';

    public function __construct(private ProtocolNutrition $nutrition) {}

    /**
     * @param  bool  $inactivePlans  Whether the app keeps paused and completed plans read-only
     *                               (ClientCapabilities::READ_ONLY_PLANS). Other apps only get a running plan.
     */
    public function build(User $user, ?Horse $horse, CarbonImmutable $now, ?string $month = null, bool $inactivePlans = false): array
    {
        $protocol = $horse?->protocols()->shownToOwner($now, $inactivePlans)->first();
        $answers = $this->nutrition->answers($protocol);
        $discovery = app(LibraryDiscovery::class);
        $access = $discovery->access($user);
        $hasPlus = $access['hasPlus'];
        $data = $protocol ? $this->protocol($protocol, $now, $month, $answers) : null;
        $items = LibraryItem::query()->where('published_at', '<=', $now->utc())->with('categories')->get();
        $unlocked = $access['unlockedIds'];
        $itemData = fn ($item) => [
            'id' => $item->id, 'title' => $item->title, 'format' => $item->format, 'heroImageUrl' => $item->hero_image_url,
            'description' => Str::limit(strip_tags($item->description ?? ''), 100), 'durationLabel' => $item->duration_label,
            'creditCost' => (int) $item->credit_cost, 'isPlus' => (bool) $item->is_plus, 'equiboostAccess' => $item->equiboost_access, 'unlocked' => in_array($item->id, $unlocked, true),
        ];
        if ($data) {
            $data['nutrition'] += $this->nutritionLinks($protocol, $items, $itemData);
        }
        $phaseText = implode(' ', array_column(array_filter($data['phases'] ?? [], fn ($p) => $p['state'] === 'active'), 'title'));
        $profileText = ($horse?->breed ?? '').' '.json_encode($answers, JSON_UNESCAPED_UNICODE);
        $tokens = fn ($text) => array_values(array_unique(array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower($text)), fn ($word) => mb_strlen($word) >= 4)));
        $phaseTokens = $tokens($phaseText);
        $profileTokens = $tokens($profileText);
        $unreadable = $items->reject(fn ($item) => $horse ? $discovery->canRead($item, $access) : in_array($item->id, $unlocked, true));
        $ranked = app(LibraryHomeSeries::class)->filter($unreadable)->map(function ($item) use ($tokens, $phaseTokens, $profileTokens, $itemData, $phaseText) {
            $words = $tokens($item->title.' '.$item->description.' '.$item->categories->pluck('label')->join(' '));
            $phaseMatch = count(array_intersect($phaseTokens, $words)) > 0;

            return $itemData($item) + ['phaseContext' => $phaseMatch ? 'Past bij '.$phaseText : null,
                'rank' => $phaseMatch ? 2 : (count(array_intersect($profileTokens, $words)) > 0 ? 1 : 0)];
        })->sortByDesc('rank')->values();
        // Rotate within relevance tiers each day; do not replace protocol relevance with randomness.
        $recommendations = $ranked->groupBy('rank')->flatMap(function ($group) use ($now) {
            $ordered = $group->sortBy('id')->values();
            $offset = ((int) $now->startOfDay()->diffInDays(CarbonImmutable::parse('2020-01-01', $now->timezone), true)) % max(1, $ordered->count());

            return $ordered->slice($offset)->concat($ordered->take($offset));
        })->take(4)->map(fn ($item) => array_diff_key($item, ['rank' => true]))->values()->all();
        $tip = SeasonalTip::query()->where('active', true)
            ->where(fn ($q) => $q->whereNull('active_from')->orWhereDate('active_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('active_to')->orWhereDate('active_to', '>=', $now))
            ->where(fn ($q) => $q->whereNotNull('active_from')->orWhere('month_order', $now->month))
            ->orderByRaw('active_from IS NULL ASC')->orderByDesc('active_from')->orderByDesc('updated_at')->first();
        $tipItem = $tip ? $items->firstWhere('id', $tip->cta_item_id) : null;
        $pageContent = PlusPage::current()->payload()['content'];

        return [
            'timezone' => $now->timezoneName, 'generatedAt' => $now->toIso8601String(), 'date' => $now->toDateString(),
            'greeting' => ($now->hour < 12 ? 'Goedemorgen' : ($now->hour < 18 ? 'Goedemiddag' : 'Goedenavond')).', '.explode(' ', trim($user->name))[0],
            'horse' => ['id' => $horse?->id ?? '', 'name' => $horse?->name ?? ''],
            'hasPlus' => $hasPlus, 'showPlusUpsell' => $horse && $horse->owner_id === $user->id && ! app(ProtocolAccess::class)->existingForHorse($user->id, $horse->id)
                // A finished plan stays readable, and the owner can buy the next one beside it.
                && (! $protocol || $protocol->status === 'completed'),
            'homeUpsell' => ['title' => $pageContent['homeTitle'], 'body' => $pageContent['homeBody'], 'button' => $pageContent['homeButton']],
            'variant' => $horse ? ($hasPlus || $protocol ? 'plus' : 'basic') : 'without-horse',
            'credits' => app(CreditLedger::class)->summary($user)['balance'],
            'plusOffer' => null,
            'seasonalTip' => $tip ? ['id' => $tip->id, 'intro' => Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags($tip->body))), 180), 'month' => $tip->month, 'title' => $tip->title ?: $tipItem?->title, 'body' => $tip->body, 'item' => $tipItem ? $itemData($tipItem) : null] : null,
            'recommendations' => $recommendations, 'protocol' => $data,
            ...app(ProtocolEvaluation::class)->dashboardLinks($user, $horse?->id, $now),
            'horseProtocols' => $this->horseProtocols($user, $horse, $now, $inactivePlans),
        ];
    }

    /**
     * Where a protocol stands on $now: day, week and progress since its start,
     * and whether it starts soon, runs or has finished.
     */
    private function progress(Protocol $protocol, CarbonImmutable $now): array
    {
        $protocol->loadMissing('phases.weeks');
        $start = $protocol->started_at ? CarbonImmutable::parse($protocol->started_at->toDateString(), $now->timezone)->startOfDay() : null;
        $totalWeeks = $protocol->scheduledWeeks();
        $totalDays = $totalWeeks * 7;
        $elapsed = $start ? (int) $start->diffInDays($now->startOfDay(), false) + 1 : 0;
        $day = max(0, min($totalDays, $elapsed));
        $completion = app(ProtocolCompletion::class);
        // An early completion ends progress on its last plan day, not on today.
        if ($start && ($last = $completion->lastPlanDay($protocol))) {
            $day = max(0, min($day, (int) $start->diffInDays(CarbonImmutable::parse($last, $now->timezone), false) + 1));
        }
        $progressKnown = ! $completion->unknownEnd($protocol);
        if (! $progressKnown) $day = 0;
        $running = $protocol->status !== 'completed' && $elapsed > 0 && $elapsed <= $totalDays;
        // Completed, or past its last scheduled day (a paused plan stays paused).
        $finished = $protocol->status !== 'paused' && ($protocol->status === 'completed' || ($start && $elapsed > $totalDays));

        return [
            'start' => $start, 'day' => $day, 'week' => $day > 0 ? (int) ceil($day / 7) : 0, 'totalWeeks' => $totalWeeks, 'running' => $running, 'progressKnown' => $progressKnown,
            'progressPercent' => $totalDays ? (int) round($day / $totalDays * 100) : 0, 'finished' => $finished,
            'statusLabel' => match (true) {
                $protocol->status === 'paused' => 'Je protocol is gepauzeerd',
                $finished => 'Je protocol is afgerond',
                ! $start => 'Je protocol staat klaar',
                $running => 'Je protocol is actief',
                default => 'Je protocol start binnenkort',
            },
        ];
    }

    /** The light version of protocol() for a card: progress, status and the active phases. */
    public function summary(Protocol $protocol, CarbonImmutable $now): array
    {
        $progress = $this->progress($protocol, $now);
        $availability = app(ProtocolPhaseAvailability::class);
        $active = $protocol->phases->filter(fn ($phase) => $availability->forPhase($protocol, $phase, $now)['state'] === 'active');

        return [
            'id' => $protocol->id, 'currentDay' => $progress['day'], 'currentWeek' => $progress['week'],
            'preparing' => ! $progress['start'], 'paused' => $protocol->status === 'paused', 'finished' => $progress['finished'], 'startedAt' => $progress['start']?->toDateString(),
            'totalWeeks' => $progress['totalWeeks'], 'progressPercent' => $progress['progressPercent'], 'progressKnown' => $progress['progressKnown'],
            'statusLabel' => $progress['statusLabel'], 'phaseLabel' => $active->pluck('title')->implode(' · '),
            'evaluation' => app(ProtocolEvaluation::class)->timing($protocol, $now),
        ];
    }

    /**
     * Per horse the protocol in progress (the one the horse's dashboard shows):
     * the user's active horses plus the selected one, sorted by name, so Home can
     * show a card for each from this one response.
     */
    private function horseProtocols(User $user, ?Horse $horse, CarbonImmutable $now, bool $inactivePlans): array
    {
        $horses = $user->horses()->where('status', 'active')->get();
        if ($horse && ! $horses->contains('id', $horse->id)) {
            $horses->push($horse);
        }
        // A paused plan has its own notice on Home (PurchasedProtocols), so it gets no card here,
        // and neither does an older completed plan of that horse.
        $winner = Protocol::shownToOwner($now, $inactivePlans)->select('id')
            ->whereColumn('protocols.horse_id', 'horses.id')->limit(1);
        $winnerIds = Horse::whereKey($horses->modelKeys())->selectSub($winner, 'protocol_id');
        $latest = Protocol::whereIn('id', $winnerIds)->where('status', '!=', 'paused')
            ->with('phases.weeks', 'horse')->get()->keyBy('horse_id');

        return $horses->filter(fn ($item) => $latest->has($item->id))
            ->sortBy(fn ($item) => Str::lower(Str::ascii((string) $item->name)))
            ->map(fn ($item) => ['horseId' => $item->id, 'horseName' => (string) $item->name] + $this->summary($latest->get($item->id), $now))
            ->values()->all();
    }

    public function protocol(Protocol $protocol, CarbonImmutable $now, ?string $month = null, ?array $answers = null, bool $preview = false): array
    {
        $answers ??= $this->nutrition->answers($protocol);
        $protocol->loadMissing(['phases.weeks', 'phases.supplements.weeks', 'phases.supplements.intakes', 'phases.supplements.supplement', 'analysis.advice', 'voedingAdviezen', 'managementAdviezen', 'bewegingAdviezen', 'manualAdviezen.phases']);
        ['start' => $start, 'day' => $day, 'week' => $week, 'totalWeeks' => $totalWeeks, 'running' => $running, 'progressPercent' => $progressPercent, 'progressKnown' => $progressKnown, 'statusLabel' => $statusLabel, 'finished' => $finished] = $this->progress($protocol, $now);
        $buildup = app(ProtocolDoseBuildup::class);
        $selections = $protocol->phases->flatMap->supplements->keyBy('id');
        $phases = $protocol->phases->map(function ($phase) use ($protocol, $now, $preview, $buildup) {
            $availability = app(ProtocolPhaseAvailability::class)->forPhase($protocol, $phase, $now);
            $weeks = $phase->weeks->pluck('protocol_week_number', 'id');

            return $availability + [
                'id' => $phase->id, 'title' => $phase->title,
                'description' => $phase->description,
                'contentAvailable' => $preview || $availability['accessible'],
                'supplements' => ($preview || $availability['accessible']) ? $phase->supplements->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name, 'dosage' => $s->dosage,
                    'description' => $s->description, 'instructions' => $s->instructions,
                    'buildupLabel' => $buildup->label($s->dose_buildup),
                    'buildup' => $buildup->presentation($s, $now->toDateString()),
                    'frequencyLabel' => $s->administrationsPerDay().'× per dag',
                    'administrationsPerDay' => $s->administrationsPerDay(), 'phaseId' => $phase->id,
                    'weekNumbers' => $s->weeks->map(fn ($link) => (int) $weeks->get($link->protocol_phase_week_id))->filter()->values()->all(),
                    'phaseTitle' => $phase->title,
                ])->values()->all() : [],
            ];
        })->values()->all();
        $allSupplements = collect($phases)->flatMap(fn ($p) => $p['supplements']);
        $monthDate = $month ? CarbonImmutable::createFromFormat('!Y-m', $month, $now->timezone) : $now->startOfMonth();
        $history = app(ProtocolDayHistory::class);
        if (! $preview) {
            $history->preserve($protocol, $now);
        }
        $historicalDays = $preview ? collect() : $history->days($protocol, $monthDate->format('Y-m'));
        $todayDate = $now->toDateString();
        $intakes = $preview ? collect() : $history->recordedIntakes($protocol, min($monthDate->startOfMonth()->toDateString(), $todayDate), max($monthDate->endOfMonth()->toDateString(), $todayDate));
        $rowsFor = function (int $number, string $date) use ($allSupplements, $intakes, $selections, $history) {
            $records = $intakes->get($date, collect());
            $planned = $allSupplements->filter(fn ($s) => in_array($number, $s['weekNumbers'], true));
            return $history->withRecordedDoses($history->plannedRows($planned, $selections, $records, $date), $records);
        };
        $completion = app(ProtocolCompletion::class);
        $closedPeriods = $completion->closedPeriods($protocol);
        $withinSchedule = $running || ($week > 0 && $protocol->completed_on && $start
            && $todayDate < $start->addWeeks($totalWeeks)->toDateString());
        $today = $withinSchedule && ! $completion->containsDate($closedPeriods, $todayDate)
            ? $rowsFor($week, $todayDate) : $history->withRecordedDoses(collect(), $intakes->get($todayDate, collect()));
        if (! $preview) {
            $today = $today->concat($history->removedDosesOn($protocol, $todayDate));
        }
        $scheduledToday = ProtocolDayHistory::scheduled($today);
        $done = $scheduledToday->where('done', true)->count();
        $notifications = [];
        $reminderDay = (int) ($protocol->customer_settings['weekly_update_day'] ?? 7);
        $weeklyDue = ! $preview && $protocol->status === 'active' && $running && (($day - 1) % 7 + 1) >= $reminderDay
            && ! DB::table('protocol_weekly_updates')->where('protocol_id', $protocol->id)->where('week_number', $week)->exists();
        if ($weeklyDue) {
            $notifications[] = ['id' => 'weekly-'.$week, 'type' => 'weekly_update', 'title' => 'Weekupdate invullen', 'body' => 'Hoe gaat het met '.$protocol->horse->name.' in week '.$week.'?', 'items' => []];
        }
        foreach ($phases as $phase) {
            if ($protocol->status === 'active' && $phase['state'] === 'preview') {
                $notifications[] = ['id' => $phase['id'], 'type' => 'next_phase', 'title' => 'Je volgende fase staat klaar 🌿',
                    'body' => 'Over een week start '.$phase['title'].'. Bekijk alvast wat er verandert en welke kruiden of supplementen je nodig hebt.', 'phaseId' => $phase['id'],
                    'items' => array_values(array_filter($phase['supplements'], fn ($s) => in_array($phase['weekStart'], $s['weekNumbers'], true))),
                    'orderItems' => array_values(array_filter($phase['supplements'], fn ($s) => count($s['weekNumbers']) > 0))];
            }
        }
        $cells = array_fill(0, $monthDate->startOfMonth()->dayOfWeekIso - 1, null);
        foreach (range(1, $monthDate->daysInMonth) as $dayNumber) {
            $date = $monthDate->setDay($dayNumber);
            $iso = $date->toDateString();
            $n = $start ? (int) floor($start->diffInDays($date->startOfDay(), false) / 7) + 1 : 0;
            // After an early completion (or for a completed plan whose last day is unknown), a day that was
            // never frozen is no plan day: the live fallback must not show it as missed or count it.
            $afterPlan = ! $preview && $completion->containsDate($closedPeriods, $iso);
            $rows = $iso === $todayDate ? $today : ($afterPlan ? collect() : $rowsFor($n, $iso));
            $scheduled = ProtocolDayHistory::scheduled($rows);
            $completed = $scheduled->where('done', true)->count();
            $state = 'default';
            if ($scheduled->isNotEmpty() && $iso <= $now->toDateString()) {
                $state = $completed === $scheduled->count() ? 'complete' : ($completed ? 'partial' : ($iso < $now->toDateString() ? 'missed' : 'default'));
            }
            if ($iso < $now->toDateString() && $historicalDays->has($iso)) {
                $rows = collect($historicalDays->get($iso));
                $scheduled = ProtocolDayHistory::scheduled($rows);
                $completed = $scheduled->where('done', true)->count();
                $state = $history->state($rows->all());
            }
            $cells[] = ['available' => (bool) $start && $iso < $now->toDateString() && $historicalDays->has($iso), 'date' => $iso, 'day' => $dayNumber, 'state' => $state, 'isToday' => (bool) $start && ! $afterPlan && $iso === $now->toDateString(),
                'total' => $iso <= $now->toDateString() ? $scheduled->count() : 0, 'done' => $iso <= $now->toDateString() ? $completed : 0,
                'recordedOnly' => $rows->isNotEmpty() && $rows->every(fn ($row) => $row['recordedOnly'] ?? false)];
        }
        while (count($cells) % 7) {
            $cells[] = null;
        }

        $manual = $this->manualAdvice($protocol, $phases, $preview);

        return [
            'id' => $protocol->id, 'title' => $protocol->title, 'currentDay' => $day, 'currentWeek' => $week,
            'preparing' => ! $start, 'paused' => $protocol->status === 'paused', 'completed' => $protocol->status === 'completed', 'finished' => $finished, 'startedAt' => $start?->toDateString(),
            'totalWeeks' => $totalWeeks, 'progressPercent' => $progressPercent, 'progressKnown' => $progressKnown, 'statusLabel' => $statusLabel,
            'todayLabel' => ! $progressKnown ? $statusLabel : (! $start ? 'Je protocol staat klaar' : 'Week '.$week.' van '.$totalWeeks.' · '.$now->locale('nl')->translatedFormat('l j F')),
            'dateLabel' => $now->locale('nl')->translatedFormat('l j F'),
            'weekLabel' => $progressKnown ? 'Week '.$week.' van '.$totalWeeks : $statusLabel,
            'phaseLabel' => implode(' · ', array_column(array_filter($phases, fn ($p) => $p['state'] === 'active'), 'title')),
            'phases' => $phases, 'today' => ['items' => $today->all(), 'done' => $done, 'total' => $scheduledToday->count(), 'percentage' => $scheduledToday->isEmpty() ? 0 : (int) round($done / $scheduledToday->count() * 100)],
            'notifications' => $notifications,
            'orderItems' => app(ProtocolOrderList::class)->forProtocol($protocol),
            'orderDiscount' => ProtocolOrderList::DISCOUNT,
            'orderedKeys' => array_values($protocol->ordered_product_keys ?? []),
            'orderOnceDaily' => (bool) $protocol->order_once_daily,
            'calendar' => ['month' => $monthDate->format('Y-m'), 'label' => $monthDate->locale('nl')->translatedFormat('F Y'), 'previousMonth' => $monthDate->subMonth()->format('Y-m'), 'nextMonth' => $monthDate->addMonth()->format('Y-m'), 'cells' => $cells],
            'nutrition' => $this->nutrition->forProtocol($protocol, $answers) + ['advice' => $this->nutritionAdvice($protocol, $manual)],
            'management' => $this->management($protocol, $manual),
            'movement' => $this->movement($protocol, $manual),
            'analysis' => filled($protocol->analysis?->summary) ? [
                'summary' => $protocol->analysis->summary,
                'priorities' => collect($protocol->analysis->focus_points ?? [])->take(4)
                    ->map(fn ($point, $index) => ['id' => 'focus-'.$index, 'title' => $point['title'], 'body' => $point['body']])->values()->all(),
                'observations' => $protocol->analysis->observations ?? [],
                'expectationFollowUp' => $protocol->analysis->expectation_follow_up,
            ] : null,
        ];
    }

    /**
     * The library items the Voeding tab links to. Hay: the configured item, else one about hay analysis.
     * Water: always the fixed drinking-water article (included in EquiBoost), never another item.
     */
    public function nutritionLinks(Protocol $protocol, $items, callable $itemData): array
    {
        $hay = $items->firstWhere('id', $protocol->customer_settings['hay_library_item_id'] ?? null)
            ?? $items->first(fn ($item) => str_contains(mb_strtolower($item->slug.' '.$item->title), 'hooianalyse'))
            ?? $items->first(fn ($item) => preg_match('/hooi.*analy|analy.*hooi/isu', $item->title.' '.$item->description) === 1);
        $water = $items->first(fn ($item) => Str::slug($item->title) === self::WATER_ARTICLE_SLUG);

        return ['hayLibraryItem' => $hay ? $itemData($hay) : null, 'waterLibraryItem' => $water ? $itemData($water) : null];
    }

    /**
     * Manual advice per category, in the editor's order. Advice limited to phases shows once one of
     * those phases is accessible, like the phase's own content; the admin preview shows everything.
     *
     * @param  array<int, array<string, mixed>>  $phases  Dashboard phases, with their availability.
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function manualAdvice(Protocol $protocol, array $phases, bool $preview): array
    {
        $accessible = collect($phases)->filter(fn (array $phase) => $phase['accessible'])->pluck('id')->flip();
        $items = [];
        foreach ($protocol->manualAdviezen as $advice) {
            $linked = $advice->applies_to === 'phases' ? $advice->phases : collect();
            if ($advice->applies_to === 'phases' && ! $preview && ! $linked->contains(fn ($phase) => $accessible->has($phase->id))) {
                continue;
            }
            $items[$advice->category][] = [
                'id' => $advice->id, 'title' => $advice->title, 'description' => $advice->description,
                'action' => $advice->action ?? 'do', 'note' => null, 'frequency' => null, 'url' => null, 'ctaLabel' => null,
                'category' => $advice->management_category,
                'manual' => true,
                'phaseIds' => $linked->pluck('id')->values()->all(),
                'phaseLabel' => $linked->isEmpty() ? null : 'Fase: '.$linked->pluck('title')->implode(' · '),
            ];
        }

        return $items;
    }

    /** @param  array<string, array<int, array<string, mixed>>>  $manual */
    private function nutritionAdvice(Protocol $protocol, array $manual = []): array
    {
        $overrides = collect($protocol->customer_settings['nutrition_advice'] ?? [])->keyBy('id');

        return $protocol->voedingAdviezen->map(fn ($advice) => Arr::only(
            $this->adviceItem($advice, $overrides->get($advice->voeding_advies_id, [])),
            ['id', 'title', 'description'],
        ))->values()->concat(array_map(
            fn (array $item) => Arr::only($item, ['id', 'title', 'description', 'manual', 'phaseIds', 'phaseLabel']),
            $manual['voeding'] ?? [],
        ))->all();
    }

    /** @param  array<string, array<int, array<string, mixed>>>  $manual */
    private function movement(Protocol $protocol, array $manual = []): array
    {
        $overrides = collect($protocol->customer_settings['movement'] ?? [])->keyBy('id');

        return $protocol->bewegingAdviezen
            ->map(fn ($advice) => $this->adviceItem($advice, $overrides->get($advice->beweging_advies_id, [])))
            ->values()
            ->concat(array_map(fn (array $item) => Arr::except($item, ['category']), $manual['beweging'] ?? []))
            ->all();
    }

    /** @param  array<string, array<int, array<string, mixed>>>  $manual */
    private function management(Protocol $protocol, array $manual = []): array
    {
        $overrides = collect($protocol->customer_settings['management'] ?? [])->keyBy('id');
        $items = $protocol->managementAdviezen->map(function ($a) use ($overrides) {
            $settings = $overrides->get($a->management_advies_id, []);
            $category = $settings['category'] ?? (preg_match('/mestonderzoek|bloed|urine|onderzoek|monitor/iu', $a->title) ? 'monitoring' : (preg_match('/hoef|gebit|tand|behandel|verzorg/iu', $a->title) ? 'care' : 'environment'));

            return $this->adviceItem($a, $settings) + ['category' => $category];
        })->values()->concat($manual['management'] ?? []);

        return collect(['environment' => 'Weide & leefomgeving', 'care' => 'Verzorging & lichamelijke ondersteuning', 'monitoring' => 'Onderzoek & monitoring'])
            ->map(fn ($title, $key) => ['id' => $key, 'title' => $title, 'items' => $items->where('category', $key)->values()->all()])
            ->filter(fn ($group) => count($group['items']) > 0)->values()->all();
    }

    /** @param  array<string, mixed>  $settings  The protocol's customer override for this advice. */
    private function adviceItem($advice, array $settings): array
    {
        return ['id' => $advice->id, 'title' => $advice->title, 'description' => $settings['instruction'] ?? $advice->description,
            'action' => $settings['action'] ?? 'do', 'note' => $settings['note'] ?? null,
            'frequency' => $settings['frequency'] ?? null, 'url' => $settings['url'] ?? null, 'ctaLabel' => $settings['cta_label'] ?? null];
    }
}
