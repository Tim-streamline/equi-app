<?php

namespace App\Support;

use App\Models\Protocol;
use App\Models\ProtocolPhaseSupplement;
use App\Models\ProtocolSupplementIntake;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProtocolDayHistory
{
    public function timezone(Protocol $protocol): string
    {
        return app(ProtocolCompletion::class)->timezone($protocol);
    }

    /** Freeze elapsed days before a plan is edited, including days never opened in the app. */
    public function preserve(Protocol $protocol, CarbonImmutable $now): void
    {
        if (! $protocol->started_at || ! $protocol->published_at || $protocol->published_at->isFuture()) {
            return;
        }

        DB::transaction(function () use ($protocol, $now) {
            $protocol = Protocol::query()->lockForUpdate()->findOrFail($protocol->id);
            $protocol->load('phases.weeks', 'phases.supplements.weeks', 'phases.supplements.intakes', 'phases.supplements.supplement');
            $start = CarbonImmutable::parse($protocol->started_at->toDateString(), $now->timezone);
            $weeks = $protocol->scheduledWeeks();
            $end = $now->startOfDay()->subDay()->min($start->addWeeks($weeks)->subDay());
            // Days after an early completion are not plan days: never freeze them as missed. Without a known
            // last day (an old completion) nothing new is frozen.
            $completion = app(ProtocolCompletion::class);
            if ($completion->unknownEnd($protocol)) {
                return;
            }
            if ($last = $completion->lastPlanDay($protocol)) {
                $end = $end->min(CarbonImmutable::parse($last, $now->timezone));
            }
            if ($end->lt($start)) {
                return;
            }
            $existing = DB::table('protocol_day_snapshots')->where('protocol_id', $protocol->id)->pluck('date')->flip();
            $closedPeriods = $completion->closedPeriods($protocol);
            $intakes = $protocol->horse->supplementIntakes()->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()->groupBy(fn ($row) => $row->date->toDateString());
            $selections = $protocol->phases->flatMap->supplements->keyBy('id');
            for ($day = $start; $day->lte($end); $day = $day->addDay()) {
                $date = $day->toDateString();
                if ($existing->has($date) || $completion->containsDate($closedPeriods, $date)) {
                    continue;
                }
                $week = (int) floor($start->diffInDays($day) / 7) + 1;
                $planned = $protocol->phases->flatMap(function ($phase) use ($week) {
                    $weekIds = $phase->weeks->where('protocol_week_number', $week)->pluck('id');

                    return $phase->supplements->filter(fn ($item) => $item->weeks->contains(fn ($link) => $weekIds->contains($link->protocol_phase_week_id)))
                        ->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'description' => $item->description,
                            'instructions' => $item->instructions, 'phaseTitle' => $phase->title, 'phaseId' => $phase->id,
                            'administrationsPerDay' => $item->administrationsPerDay()]);
                });
                $items = $this->plannedRows($planned, $selections, $intakes->get($date, collect()), $date)->all();
                DB::table('protocol_day_snapshots')->insert([
                    'protocol_id' => $protocol->id, 'date' => $date, 'items' => json_encode($items),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    /** Expand the schedule in force on a date; prefer the amount actually given on that date. */
    public function plannedRows(Collection $planned, Collection $selections, Collection $intakes, string $date): Collection
    {
        $records = $intakes->keyBy(fn ($row) => $row->protocol_phase_supplement_id.':'.$row->administration);
        $buildup = app(ProtocolDoseBuildup::class);

        return $planned->flatMap(function ($item) use ($records, $selections, $buildup, $date) {
            $rows = [];
            foreach (range(1, $item['administrationsPerDay']) as $number) {
                $record = $records->get($item['id'].':'.$number);
                $rows[] = array_replace($item, ['administration' => $number, 'done' => (bool) $record?->done,
                    'dosage' => $record?->dosage ?? $buildup->forDay($selections->get($item['id']), $date)]);
            }
            return $rows;
        })->values();
    }

    /** A frequency change may leave recorded doses outside the known schedule. Keep those facts read-only. */
    public function withRecordedDoses(Collection $rows, Collection $doses): Collection
    {
        $keys = $rows->mapWithKeys(fn ($row) => [$row['id'].':'.($row['administration'] ?? 1) => true]);
        foreach ($doses as $dose) {
            if (! $dose->done || $keys->has($dose->protocol_phase_supplement_id.':'.$dose->administration)) continue;
            $item = $dose->protocolPhaseSupplement;
            $rows->push(['id' => $item->id, 'name' => $item->name, 'dosage' => $dose->dosage,
                'description' => $item->description, 'instructions' => $item->instructions,
                'phaseTitle' => $item->phase->title, 'phaseId' => $item->protocol_phase_id,
                'administration' => $dose->administration, 'administrationsPerDay' => max($dose->administration, $item->administrationsPerDay()),
                'done' => true, 'recordedOnly' => true]);
        }
        return $rows;
    }

    /** Called under the protocol write lock, immediately before deleting the selections and their intake rows. */
    public function preserveRemovedDoses(string $protocolId, array $selectionIds): void
    {
        if (! $selectionIds) {
            return;
        }
        $byDate = ProtocolSupplementIntake::whereIn('protocol_phase_supplement_id', $selectionIds)
            ->where('done', true)->with('protocolPhaseSupplement.phase', 'protocolPhaseSupplement.supplement')
            ->orderBy('protocol_phase_supplement_id')->orderBy('administration')->get()
            ->groupBy(fn ($intake) => $intake->date->toDateString());
        foreach ($byDate->chunk(500) as $chunk) {
            $previous = DB::table('protocol_removed_doses')->where('protocol_id', $protocolId)
                ->whereIn('date', $chunk->keys()->all())->pluck('items', 'date');
            $rows = $chunk->map(fn ($doses, $date) => ['protocol_id' => $protocolId, 'date' => $date,
                'items' => json_encode($this->withRecordedDoses(collect(json_decode($previous[$date] ?? '[]', true)), $doses)->all(), JSON_THROW_ON_ERROR)]);
            DB::table('protocol_removed_doses')->upsert($rows->values()->all(), ['protocol_id', 'date'], ['items']);
        }
    }

    public function removedDosesOn(Protocol $protocol, string $date): array
    {
        $items = DB::table('protocol_removed_doses')->where('protocol_id', $protocol->id)->where('date', $date)->value('items');

        return json_decode($items ?? '[]', true);
    }

    public function days(Protocol $protocol, string $month): Collection
    {
        return $this->historyBetween($protocol, $month.'-01', CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString());
    }

    private function historyBetween(Protocol $protocol, string $from, string $to): Collection
    {
        $completion = app(ProtocolCompletion::class);
        $closedPeriods = array_filter($completion->closedPeriods($protocol), fn ($period) =>
            ($period['after'] === null || $period['after'] < $to) && ($period['before'] === null || $period['before'] > $from));
        $frozen = DB::table('protocol_day_snapshots')->where('protocol_id', $protocol->id)
            ->whereBetween('date', [$from, $to])->get()
            ->mapWithKeys(fn ($row) => [$row->date => $this->displayItems(json_decode($row->items, true))])
            ->filter(fn ($items, $date) => ! $completion->containsDate($closedPeriods, $date) || collect($items)->contains('done', true));
        $records = $this->recordedIntakes($protocol, $from, $to);
        foreach ($records as $date => $doses) {
            $rows = collect($frozen->get($date, []));
            $combined = $this->withRecordedDoses($rows, $doses);
            if ($combined->isNotEmpty()) $frozen->put($date, $combined->all());
        }

        $removed = DB::table('protocol_removed_doses')->where('protocol_id', $protocol->id)
            ->whereBetween('date', [$from, $to])->pluck('items', 'date');
        foreach ($removed as $date => $items) {
            // A scheduled row keeps its original state and remains independently correctable.
            $rows = collect($frozen->get($date, []))->concat(json_decode($items, true))
                ->unique(fn ($row) => $row['id'].':'.($row['administration'] ?? 1))->values();
            $frozen->put($date, $rows->all());
        }

        return $frozen;
    }

    public function recordedIntakes(Protocol $protocol, string $from, string $to): Collection
    {
        return ProtocolSupplementIntake::where('horse_id', $protocol->horse_id)->whereBetween('date', [$from, $to])
            ->whereHas('protocolPhaseSupplement.phase', fn ($query) => $query->where('protocol_id', $protocol->id))
            ->with('protocolPhaseSupplement.phase', 'protocolPhaseSupplement.supplement')
            ->orderBy('protocol_phase_supplement_id')->orderBy('administration')->get()->groupBy(fn ($row) => $row->date->toDateString());
    }

    public function day(Protocol $protocol, string $date, CarbonImmutable $now): array
    {
        abort_unless($date < $now->toDateString(), 422, 'Open vandaag via Vandaag. Toekomstige dagen zijn niet beschikbaar.');
        $this->preserve($protocol, $now);
        $items = $this->historyBetween($protocol, $date, $date)->get($date);
        abort_if($items === null, 404, 'Op deze datum was er geen protocol gepland.');

        return [
            'date' => $date, 'label' => CarbonImmutable::parse($date, $now->timezone)->locale('nl')->translatedFormat('l j F Y'),
            'editable' => $protocol->status === 'active' && $date >= $now->subDays(7)->toDateString()
                && ! app(ProtocolCompletion::class)->containsDate($protocol->closed_periods ?? [], $date),
            'editableUntil' => CarbonImmutable::parse($date, $now->timezone)->addDays(7)->toDateString(),
            'weekNumber' => $protocol->started_at ? (int) floor(CarbonImmutable::parse($protocol->started_at->toDateString(), $now->timezone)->diffInDays(CarbonImmutable::parse($date, $now->timezone)) / 7) + 1 : null,
            'items' => $items, 'state' => $this->state($items),
        ];
    }

    /** Keep the existing API dosage field while preserving the frozen plan in storage. */
    private function displayItems(array $items): array
    {
        return array_map(function (array $item): array {
            if (array_key_exists('recorded_dosage', $item)) {
                $item['dosage'] = $item['recorded_dosage'];
            }

            return $item;
        }, $items);
    }

    public function state(array $items): string
    {
        $items = self::scheduled($items);
        $done = $items->filter(fn ($item) => $item['done'])->count();

        return $items->isEmpty() ? 'default' : ($done === $items->count() ? 'complete' : ($done ? 'partial' : 'missed'));
    }

    /** The day's scheduled doses: recorded-only rows are shown but never count as planned or missed. */
    public static function scheduled(iterable $rows): Collection
    {
        return collect($rows)->reject(fn ($row) => $row['recordedOnly'] ?? false);
    }

    /**
     * Check or uncheck one dose, or with $administration null every dose of the item on
     * that day: the whole-day checkbox of released apps (see LegacyProtocolClient).
     */
    public function setDone(Protocol $protocol, string $date, string $itemId, bool $done, CarbonImmutable $now, bool $mirrorIntake = true, ?int $administration = 1, bool $allowCompletedUpload = false): array
    {
        return DB::transaction(function () use ($protocol, $date, $itemId, $done, $now, $mirrorIntake, $administration, $allowCompletedUpload) {
            $protocol = Protocol::query()->lockForUpdate()->findOrFail($protocol->id);
            $day = $this->day($protocol, $date, $now);
            $lateUpload = $allowCompletedUpload && $protocol->status === 'completed' && $protocol->completed_on
                && $date <= $protocol->completed_on->toDateString() && $date >= $now->subDays(7)->toDateString()
                && ! app(ProtocolCompletion::class)->containsDate($protocol->closed_periods ?? [], $date);
            abort_unless($day['editable'] || $lateUpload, 422, 'Je kunt alleen de afgelopen 7 dagen aanpassen.');
            // Mutate raw storage, never the response with recorded doses substituted.
            $items = json_decode(DB::table('protocol_day_snapshots')->where('protocol_id', $protocol->id)
                ->where('date', $date)->value('items') ?? '[]', true);
            $doses = collect($items)->filter(fn ($item) => $item['id'] === $itemId);
            $targets = $doses->filter(fn ($item) => $administration === null || ($item['administration'] ?? 1) === $administration);
            abort_if($targets->isEmpty(), 422, 'Dit item stond niet op deze dag gepland.');
            foreach ($targets as $index => $item) {
                $items[$index]['done'] = $done;
            }
            // Keep existing devices in sync, but history also works after a live item is removed.
            if (ProtocolPhaseSupplement::whereKey($itemId)->exists()) {
                if ($mirrorIntake) {
                    $recorded = ProtocolSupplementIntake::where('protocol_phase_supplement_id', $itemId)
                        ->where('date', $date)->orderBy('created_at')->orderBy('id')->first();
                    foreach ($targets as $item) {
                        ProtocolSupplementIntake::updateOrCreate(['protocol_phase_supplement_id' => $itemId, 'date' => $date, 'administration' => $item['administration'] ?? 1], [
                            'horse_id' => $protocol->horse_id, 'dosage' => $recorded ? $recorded->dosage : $this->displayItems([$item])[0]['dosage'],
                            'done' => $done, 'taken_at' => $done ? now() : null,
                        ]);
                    }
                }
                app(LegacyProtocolClient::class)->refreshDayDone($itemId, $date, $doses->count());
                $intakes = ProtocolSupplementIntake::where('protocol_phase_supplement_id', $itemId)
                    ->where('date', $date)->get()->keyBy('administration');
                // Include the morning compatibility row created for an evening-first upload.
                // Only the requested targets above have their checkbox state changed.
                foreach ($doses as $index => $item) {
                    $intake = $intakes->get($item['administration'] ?? 1);
                    if ($intake && ! array_key_exists('recorded_dosage', $item)) {
                        $items[$index]['recorded_dosage'] = $intake->dosage;
                    }
                }
            }
            DB::table('protocol_day_snapshots')->where('protocol_id', $protocol->id)->where('date', $date)
                ->update(['items' => json_encode($items), 'updated_at' => now()]);
            return $this->day($protocol, $date, $now);
        });
    }
}
