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
            $protocol->load('phases.weeks', 'phases.supplements.weeks');
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
                $done = $intakes->get($date, collect())->keyBy('protocol_phase_supplement_id');
                $items = $protocol->phases->flatMap(function ($phase) use ($week, $done) {
                    $weekIds = $phase->weeks->where('protocol_week_number', $week)->pluck('id');

                    return $phase->supplements->filter(fn ($item) => $item->weeks->contains(fn ($link) => $weekIds->contains($link->protocol_phase_week_id)))
                        ->map(fn ($item) => [
                            'id' => $item->id, 'name' => $item->name, 'dosage' => $item->dosage,
                            'description' => $item->description, 'instructions' => $item->instructions,
                            'phaseTitle' => $phase->title, 'done' => (bool) ($done->get($item->id)?->done ?? false),
                        ]);
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
            ->get()->mapWithKeys(fn ($row) => [$row->date => json_decode($row->items, true)]);
    }

    public function day(Protocol $protocol, string $date, CarbonImmutable $now): array
    {
        abort_unless($date < $now->toDateString(), 422, 'Open vandaag via Vandaag. Toekomstige dagen zijn niet beschikbaar.');
        $this->preserve($protocol, $now);
        $row = DB::table('protocol_day_snapshots')->where('protocol_id', $protocol->id)->where('date', $date)->first();
        abort_unless($row, 404, 'Op deze datum was er geen protocol gepland.');
        $items = json_decode($row->items, true);

        return [
            'date' => $date, 'label' => CarbonImmutable::parse($date, $now->timezone)->locale('nl')->translatedFormat('l j F Y'),
            'editable' => $date >= $now->subDays(14)->toDateString(),
            'items' => $items, 'state' => $this->state($items),
        ];
    }

    public function state(array $items): string
    {
        $done = count(array_filter($items, fn ($item) => $item['done']));

        return ! $items ? 'default' : ($done === count($items) ? 'complete' : ($done ? 'partial' : 'missed'));
    }

    public function setDone(Protocol $protocol, string $date, string $itemId, bool $done, CarbonImmutable $now, bool $mirrorIntake = true): array
    {
        return DB::transaction(function () use ($protocol, $date, $itemId, $done, $now, $mirrorIntake) {
            $protocol = Protocol::query()->lockForUpdate()->findOrFail($protocol->id);
            $day = $this->day($protocol, $date, $now);
            abort_unless($day['editable'], 422, 'Je kunt alleen de afgelopen 14 dagen aanpassen.');
            $index = array_search($itemId, array_column($day['items'], 'id'), true);
            abort_if($index === false, 422, 'Dit item stond niet op deze dag gepland.');
            $day['items'][$index]['done'] = $done;
            DB::table('protocol_day_snapshots')->where('protocol_id', $protocol->id)->where('date', $date)
                ->update(['items' => json_encode($day['items']), 'updated_at' => now()]);
            // Keep existing devices in sync, but history also works after a live item is removed.
            if ($mirrorIntake && ProtocolPhaseSupplement::whereKey($itemId)->exists()) {
                ProtocolSupplementIntake::updateOrCreate(['protocol_phase_supplement_id' => $itemId, 'date' => $date], [
                    'horse_id' => $protocol->horse_id, 'dosage' => $day['items'][$index]['dosage'],
                    'done' => $done, 'taken_at' => $done ? now() : null,
                ]);
            }
            $day['state'] = $this->state($day['items']);

            return $day;
        });
    }
}
