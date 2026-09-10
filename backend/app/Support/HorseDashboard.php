<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\LibraryItem;
use App\Models\Plan;
use App\Models\Protocol;
use App\Models\SeasonalTip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HorseDashboard
{
    public function __construct(private ProtocolNutrition $nutrition) {}

    public function build(User $user, Horse $horse, CarbonImmutable $now, ?string $month = null): array
    {
        $answers = $this->nutrition->answers($horse);
        $protocol = $horse->protocols()->where('status', 'active')->whereNotNull('published_at')
            ->where('published_at', '<=', $now)->orderByDesc('published_at')->first();
        $hasPlus = $user->subscriptions()->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('started_at')->orWhereDate('started_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhereDate('cancelled_at', '>', $now))
            ->whereHas('plan', fn ($q) => $q->where('slug', 'plus'))->exists();
        $data = $protocol ? $this->protocol($protocol, $now, $month, $answers) : null;
        $items = LibraryItem::query()->whereNotNull('published_at')->where('published_at', '<=', $now)->with('categories')->get();
        $unlocked = DB::table('library_unlocks')->where('user_id', $user->id)->pluck('item_id')->all();
        $itemData = fn ($item) => [
            'id' => $item->id, 'title' => $item->title, 'format' => $item->format, 'heroImageUrl' => $item->hero_image_url,
            'description' => Str::limit(strip_tags($item->description ?? ''), 100), 'durationLabel' => $item->duration_label,
            'creditCost' => (int) $item->credit_cost, 'unlocked' => in_array($item->id, $unlocked, true),
        ];
        $topic = fn ($term) => $items->first(fn ($item) => str_contains(mb_strtolower($item->slug.' '.$item->title), $term));
        if ($data) {
            foreach (['hayLibraryItem' => 'hooianalyse', 'waterLibraryItem' => 'wateranalyse'] as $key => $term) {
                $configuredId = $protocol->customer_settings[$key === 'hayLibraryItem' ? 'hay_library_item_id' : 'water_library_item_id'] ?? null;
                $item = $items->firstWhere('id', $configuredId) ?? $topic($term);
                if (! $item) {
                    $subject = $key === 'hayLibraryItem' ? 'hooi' : 'water';
                    $item = $items->first(fn ($item) => preg_match('/'.$subject.'.*analy|analy.*'.$subject.'/isu', $item->title.' '.$item->description) === 1);
                }
                $data['nutrition'][$key] = $item ? $itemData($item) : null;
            }
        }
        $phaseText = implode(' ', array_column(array_filter($data['phases'] ?? [], fn ($p) => $p['state'] === 'active'), 'title'));
        $profileText = $horse->breed.' '.json_encode($answers, JSON_UNESCAPED_UNICODE);
        $tokens = fn ($text) => array_values(array_unique(array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower($text)), fn ($word) => mb_strlen($word) >= 4)));
        $phaseTokens = $tokens($phaseText);
        $profileTokens = $tokens($profileText);
        $ranked = $items->map(function ($item) use ($tokens, $phaseTokens, $profileTokens, $itemData, $phaseText) {
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
        })->take(2)->map(fn ($item) => array_diff_key($item, ['rank' => true]))->values()->all();
        $tip = SeasonalTip::query()->where('active', true)
            ->where(fn ($q) => $q->whereNull('active_from')->orWhereDate('active_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('active_to')->orWhereDate('active_to', '>=', $now))
            ->where(fn ($q) => $q->whereNotNull('active_from')->orWhere('month_order', $now->month))
            ->orderByRaw('active_from IS NULL ASC')->orderByDesc('active_from')->orderByDesc('updated_at')->first();
        $tipItem = $tip ? $items->firstWhere('id', $tip->cta_item_id) : null;
        $plusPlan = Plan::query()->where('slug', 'plus')->with('benefits')->first();

        return [
            'generatedAt' => $now->toIso8601String(), 'date' => $now->toDateString(),
            'greeting' => ($now->hour < 12 ? 'Goedemorgen' : ($now->hour < 18 ? 'Goedemiddag' : 'Goedenavond')).', '.explode(' ', trim($user->name))[0],
            'horse' => ['id' => $horse->id, 'name' => $horse->name],
            'hasPlus' => $hasPlus, 'showPlusUpsell' => ! $hasPlus && ! $protocol,
            'variant' => $hasPlus || $protocol ? 'plus' : 'basic',
            'credits' => (int) (DB::table('library_credit_balances')->where('user_id', $user->id)->value('balance') ?? 0),
            'plusOffer' => $plusPlan ? ['name' => $plusPlan->name, 'description' => $plusPlan->description,
                'priceLabel' => ($plusPlan->currency === 'EUR' ? '€' : $plusPlan->currency).' '.number_format($plusPlan->price_cents / 100, 2, ',', '.'),
                'priceSuffix' => $plusPlan->price_suffix, 'benefits' => $plusPlan->benefits->pluck('label')->all()] : null,
            'seasonalTip' => $tip ? ['id' => $tip->id, 'intro' => Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags($tip->body))), 180), 'month' => $tip->month, 'title' => $tip->title ?: $tipItem?->title, 'body' => $tip->body, 'item' => $tipItem ? $itemData($tipItem) : null] : null,
            'recommendations' => $recommendations, 'protocol' => $data,
        ];
    }

    public function protocol(Protocol $protocol, CarbonImmutable $now, ?string $month = null, array $answers = []): array
    {
        $protocol->load(['phases.weeks', 'phases.supplements.weeks', 'analysis.advice', 'managementAdviezen', 'bewegingAdviezen']);
        $start = $protocol->started_at ? CarbonImmutable::parse($protocol->started_at->toDateString(), $now->timezone)->startOfDay() : null;
        $totalWeeks = max((int) $protocol->total_weeks, (int) $protocol->phases->flatMap->weeks->max('protocol_week_number'));
        $totalDays = $totalWeeks * 7;
        $elapsed = $start ? (int) $start->diffInDays($now->startOfDay(), false) + 1 : 0;
        $day = max(0, min($totalDays, $elapsed));
        $week = $day > 0 ? (int) ceil($day / 7) : 0;
        $running = $elapsed > 0 && $elapsed <= $totalDays;
        $phases = $protocol->phases->map(function ($phase) use ($week, $elapsed, $totalDays) {
            $weeks = $phase->weeks->pluck('protocol_week_number', 'id');
            $first = (int) ($weeks->min() ?? $phase->week_start ?? 0);
            $last = (int) ($weeks->max() ?? $phase->week_end ?? 0);
            $state = $first > 0 && $week >= $first && $week <= $last && $elapsed <= $totalDays ? 'active' : ($week > $last || $elapsed > $totalDays ? 'done' : 'upcoming');
            $range = $first ? 'Week '.$first.($last > $first ? ' t/m '.$last : '') : 'Nog niet ingepland';

            return [
                'id' => $phase->id, 'title' => $phase->title, 'description' => $phase->description,
                'weekStart' => $first, 'weekEnd' => $last, 'weekLabel' => $range, 'state' => $state,
                'statusLabel' => $state === 'active' ? 'Actief · wk '.$first.'–'.$last : ($state === 'done' ? 'Afgerond' : ($first ? 'Vanaf wk '.$first : 'Nog niet ingepland')),
                'supplements' => $phase->supplements->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name, 'dosage' => $s->dosage,
                    'description' => $s->description, 'instructions' => $s->instructions,
                    'frequencyLabel' => $s->aantal_per_week ? $s->aantal_per_week.'× per week' : null,
                    'weekNumbers' => $s->weeks->map(fn ($link) => (int) $weeks->get($link->protocol_phase_week_id))->filter()->values()->all(),
                    'phaseTitle' => $phase->title,
                ])->values()->all(),
            ];
        })->values()->all();
        $allSupplements = collect($phases)->flatMap(fn ($p) => $p['supplements']);
        $monthDate = $month ? CarbonImmutable::createFromFormat('!Y-m', $month, $now->timezone) : $now->startOfMonth();
        $intakes = $protocol->horse->supplementIntakes()->whereBetween('date', [min($monthDate->startOfMonth()->toDateString(), $now->toDateString()), max($monthDate->endOfMonth()->toDateString(), $now->toDateString())])->get()->groupBy(fn ($i) => $i->date->toDateString());
        $rowsFor = function (int $number, string $date) use ($allSupplements, $intakes) {
            $records = $intakes->get($date, collect())->keyBy('protocol_phase_supplement_id');

            return $allSupplements->filter(fn ($s) => in_array($number, $s['weekNumbers'], true))
                ->map(fn ($s) => $s + ['done' => (bool) ($records->get($s['id'])?->done ?? false)])->values();
        };
        $today = $running ? $rowsFor($week, $now->toDateString()) : collect();
        $done = $today->where('done', true)->count();
        $notifications = [];
        $reminderDay = (int) ($protocol->customer_settings['weekly_update_day'] ?? 7);
        $weeklyDue = $running && (($day - 1) % 7 + 1) >= $reminderDay
            && ! DB::table('protocol_weekly_updates')->where('protocol_id', $protocol->id)->where('week_number', $week)->exists();
        if ($weeklyDue) {
            $notifications[] = ['id' => 'weekly-'.$week, 'type' => 'weekly_update', 'title' => 'Weekupdate invullen', 'body' => 'Hoe gaat het met '.$protocol->horse->name.' in week '.$week.'?', 'items' => []];
        }
        foreach ($phases as $phase) {
            if ($running && $phase['weekStart'] === $week + 1) {
                $notifications[] = ['id' => $phase['id'], 'type' => 'next_phase', 'title' => 'Let op: volgende week start een nieuwe fase',
                    'body' => $phase['title'].' start in week '.$phase['weekStart'].'. Bekijk wat je nodig hebt.', 'phaseId' => $phase['id'],
                    'items' => array_values(array_filter($phase['supplements'], fn ($s) => in_array($week + 1, $s['weekNumbers'], true))),
                    'orderItems' => array_values(array_filter($phase['supplements'], fn ($s) => count($s['weekNumbers']) > 0))];
            }
        }
        $cells = array_fill(0, $monthDate->startOfMonth()->dayOfWeekIso - 1, null);
        foreach (range(1, $monthDate->daysInMonth) as $dayNumber) {
            $date = $monthDate->setDay($dayNumber);
            $iso = $date->toDateString();
            $n = $start ? (int) floor($start->diffInDays($date->startOfDay(), false) / 7) + 1 : 0;
            $rows = $rowsFor($n, $iso);
            $completed = $rows->where('done', true)->count();
            $state = 'default';
            if ($rows->isNotEmpty() && $iso <= $now->toDateString()) {
                $state = $completed === $rows->count() ? 'complete' : ($completed ? 'partial' : ($iso < $now->toDateString() ? 'missed' : 'default'));
            }
            $cells[] = ['date' => $iso, 'day' => $dayNumber, 'state' => $state, 'isToday' => $iso === $now->toDateString()];
        }
        while (count($cells) % 7) {
            $cells[] = null;
        }

        return [
            'id' => $protocol->id, 'title' => $protocol->title, 'currentDay' => $day, 'currentWeek' => $week,
            'totalWeeks' => $totalWeeks, 'progressPercent' => $totalDays ? (int) round($day / $totalDays * 100) : 0,
            'statusLabel' => $elapsed > $totalDays ? 'Je protocol is afgerond' : ($running ? 'Je protocol is actief' : 'Je protocol start binnenkort'),
            'todayLabel' => 'Week '.$week.' van '.$totalWeeks.' · '.$now->locale('nl')->translatedFormat('l j F'),
            'phaseLabel' => implode(' · ', array_column(array_filter($phases, fn ($p) => $p['state'] === 'active'), 'title')),
            'phases' => $phases, 'today' => ['items' => $today->all(), 'done' => $done, 'total' => $today->count(), 'percentage' => $today->isEmpty() ? 0 : (int) round($done / $today->count() * 100)],
            'notifications' => $notifications,
            'orderItems' => $allSupplements->filter(fn ($s) => count($s['weekNumbers']) > 0)->values()->all(),
            'calendar' => ['month' => $monthDate->format('Y-m'), 'label' => $monthDate->locale('nl')->translatedFormat('F Y'), 'previousMonth' => $monthDate->subMonth()->format('Y-m'), 'nextMonth' => $monthDate->addMonth()->format('Y-m'), 'cells' => $cells],
            'nutrition' => $this->nutrition->forProtocol($protocol, $answers),
            'management' => $this->management($protocol),
            'movement' => $protocol->bewegingAdviezen->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'description' => $a->description])->all(),
            'analysis' => filled($protocol->analysis?->summary) ? [
                'summary' => $protocol->analysis->summary,
                'priorities' => collect($protocol->analysis->focus_points ?? [])->take(4)
                    ->map(fn ($point, $index) => ['id' => 'focus-'.$index, 'title' => $point['title'], 'body' => $point['body']])->values()->all(),
                'observations' => $protocol->analysis->observations ?? [],
            ] : null,
        ];
    }

    private function management(Protocol $protocol): array
    {
        $overrides = collect($protocol->customer_settings['management'] ?? [])->keyBy('id');
        $items = $protocol->managementAdviezen->map(function ($a) use ($overrides) {
            $settings = $overrides->get($a->management_advies_id, []);
            $category = $settings['category'] ?? (preg_match('/mestonderzoek|bloed|urine|onderzoek|monitor/iu', $a->title) ? 'monitoring' : (preg_match('/hoef|gebit|tand|behandel|verzorg/iu', $a->title) ? 'care' : 'environment'));

            return ['id' => $a->id, 'title' => $a->title, 'description' => $settings['instruction'] ?? $a->description,
                'category' => $category, 'action' => $settings['action'] ?? 'do', 'note' => $settings['note'] ?? null,
                'frequency' => $settings['frequency'] ?? null, 'url' => $settings['url'] ?? null, 'ctaLabel' => $settings['cta_label'] ?? null];
        });

        return collect(['environment' => 'Weide & leefomgeving', 'care' => 'Verzorging & lichamelijke ondersteuning', 'monitoring' => 'Onderzoek & monitoring'])
            ->map(fn ($title, $key) => ['id' => $key, 'title' => $title, 'items' => $items->where('category', $key)->values()->all()])
            ->filter(fn ($group) => count($group['items']) > 0)->values()->all();
    }
}
