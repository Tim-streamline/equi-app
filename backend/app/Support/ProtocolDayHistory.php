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
        return $protocol->horse->owner?->notificationPreferences?->timezone ?: 'Europe/Amsterdam';
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
            $weeks = max((int) $protocol->total_weeks, (int) $protocol->phases->flatMap->weeks->max('protocol_week_number'));
            $end = $now->startOfDay()->subDay()->min($start->addWeeks($weeks)->subDay());
            if ($end->lt($start)) {
                return;
            }
            $existing = DB::table('protocol_day_snapshots')->where('protocol_id', $protocol->id)->pluck('date')->flip();
            $intakes = $protocol->horse->supplementIntakes()->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()->groupBy(fn ($row) => $row->date->toDateString());
            for ($day = $start; $day->lte($end); $day = $day->addDay()) {
                $date = $day->toDateString();
                if ($existing->has($date)) {
                    continue;
                }
                $week = (int) floor($start->diffInDays($day) / 7) + 1;
                $done = $intakes->get($date, collect())->keyBy(fn ($row) => $row->protocol_phase_supplement_id.':'.($row->administration ?? 1));
                $items = $protocol->phases->flatMap(function ($phase) use ($week, $done, $date) {
                    $weekIds = $phase->weeks->where('protocol_week_number', $week)->pluck('id');

                    return $phase->supplements->filter(fn ($item) => $item->weeks->contains(fn ($link) => $weekIds->contains($link->protocol_phase_week_id)))
                        ->flatMap(fn ($item) => collect(range(1, $item->administrationsPerDay()))->map(fn ($administration) => [
                            'id' => $item->id, 'name' => $item->name,
                            'dosage' => $done->get($item->id.':'.$administration)?->dosage ?? app(ProtocolDoseBuildup::class)->forDay($item, $date),
                            'description' => $item->description, 'instructions' => $item->instructions,
                            'phaseTitle' => $phase->title, 'phaseId' => $phase->id,
                            'administration' => $administration, 'administrationsPerDay' => $item->administrationsPerDay(),
                            'done' => (bool) ($done->get($item->id.':'.$administration)?->done ?? false),
                        ]));
                })->values()->all();
                DB::table('protocol_day_snapshots')->insert([
                    'protocol_id' => $protocol->id, 'date' => $date, 'items' => json_encode($items),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function days(Protocol $protocol, string $month): Collection
    {
        return DB::table('protocol_day_snapshots')->where('protocol_id', $protocol->id)
            ->whereBetween('date', [$month.'-01', CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString()])
            ->get()->mapWithKeys(fn ($row) => [$row->date => $this->displayItems(json_decode($row->items, true))]);
    }

    public function day(Protocol $protocol, string $date, CarbonImmutable $now): array
    {
        abort_unless($date < $now->toDateString(), 422, 'Open vandaag via Vandaag. Toekomstige dagen zijn niet beschikbaar.');
        $this->preserve($protocol, $now);
        $row = DB::table('protocol_day_snapshots')->where('protocol_id', $protocol->id)->where('date', $date)->first();
        abort_unless($row, 404, 'Op deze datum was er geen protocol gepland.');
        $items = $this->displayItems(json_decode($row->items, true));

        return [
            'date' => $date, 'label' => CarbonImmutable::parse($date, $now->timezone)->locale('nl')->translatedFormat('l j F Y'),
            'editable' => $date >= $now->subDays(14)->toDateString(),
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
        $done = count(array_filter($items, fn ($item) => $item['done']));

        return ! $items ? 'default' : ($done === count($items) ? 'complete' : ($done ? 'partial' : 'missed'));
    }

    /**
     * Check or uncheck one dose, or with $administration null every dose of the item on
     * that day: the whole-day checkbox of released apps (see LegacyProtocolClient).
     */
    public function setDone(Protocol $protocol, string $date, string $itemId, bool $done, CarbonImmutable $now, bool $mirrorIntake = true, ?int $administration = 1): array
    {
        return DB::transaction(function () use ($protocol, $date, $itemId, $done, $now, $mirrorIntake, $administration) {
            $protocol = Protocol::query()->lockForUpdate()->findOrFail($protocol->id);
            $day = $this->day($protocol, $date, $now);
            abort_unless($day['editable'], 422, 'Je kunt alleen de afgelopen 14 dagen aanpassen.');
            // Mutate raw storage, never the response with recorded doses substituted.
            $items = json_decode(DB::table('protocol_day_snapshots')->where('protocol_id', $protocol->id)
                ->where('date', $date)->value('items'), true);
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
            $day['items'] = $this->displayItems($items);
            $day['state'] = $this->state($day['items']);

            return $day;
        });
    }
}
