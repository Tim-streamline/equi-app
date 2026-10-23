<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeSafetyReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Add missing reports without replaying answer observers or replacing recovery evidence. */
class IntakeSafetyReportBackfill
{
    public function run(): void
    {
        Horse::orderBy('id')->chunkById(100, function ($horses): void {
            foreach ($horses as $horse) {
                DB::transaction(function () use ($horse): void {
                    $horse = Horse::lockForUpdate()->findOrFail($horse->id);
                    $safety = app(LaminitisSafety::class);
                    $safety->importLegacy($horse);
                    $reports = IntakeSafetyReport::where('horse_id', $horse->id)->get();
                    $history = $reports->flatMap(fn ($r) => array_map(fn ($state) => $state + array_filter([
                        'configuration' => $r->state['configuration'] ?? null,
                    ]), $r->state['history'] ?? []))->all();
                    // Collapse only versions of the same concern. Different
                    // trigger kinds or pinned rules within one intake retain
                    // independent recovery requirements and release evidence.
                    foreach (collect($history)->reverse() as $prior) {
                        $id = $prior['booking_id'] ?? null;
                        if (! $id || $reports->contains(fn ($r) => $this->sameConcern($r->state, $prior))) {
                            continue;
                        }
                        $source = IntakeBooking::where('horse_id', $horse->id)->find($id);
                        $reports->push(IntakeSafetyReport::create([
                            'horse_id' => $horse->id, 'intake_booking_id' => $source?->id,
                            'state' => $safety->legacyReportState($prior, $source),
                        ]));
                    }
                    $configs = array_filter([$safety->config()]);
                    foreach ([...$reports->pluck('state')->all(), ...$history] as $state) {
                        if (! empty($state['configuration']) && ! in_array($state['configuration'], $configs, true)) {
                            $configs[] = $state['configuration'];
                        }
                    }
                    // Include submitted intakes too: their immutable answers can
                    // still describe an unresolved concern omitted by the old hold.
                    IntakeBooking::where('horse_id', $horse->id)->orderBy('id')->chunkById(100, function ($bookings) use ($horse, $safety, $reports, $configs): void {
                        foreach ($bookings as $booking) {
                            foreach ($configs as $config) {
                                $values = $safety->answers($booking, $config);
                                $rules = app(IntakeSafetySettings::class);
                                foreach ($config['triggers'] as $trigger) {
                                    if (! $rules->matches($trigger['when'], $values)) {
                                        continue;
                                    }
                                    // A recorded release is evidence, not a reason
                                    // to recreate a hold from historical answers.
                                    $covers = fn ($state) => ($state['booking_id'] ?? null) === $booking->id
                                        && (($state['kind'] ?? null) === $trigger['id'] || in_array($trigger['id'], $state['covered_triggers'] ?? [], true));
                                    if ($reports->contains(fn ($r) => $covers($r->state))) {
                                        continue;
                                    }
                                    $state = [
                                        'episode_id' => (string) Str::uuid(), 'kind' => $trigger['id'],
                                        'booking_id' => $booking->id, 'reported_at' => $booking->answers()->where('section_id', $config['section'])->max('updated_at'),
                                        'source' => 'owner_reported', 'reported_by' => $booking->user_id, 'history' => [],
                                        'client_marker' => $values[$config['marker_field']] ?? null,
                                        'trigger_values' => array_intersect_key($values, $trigger['when']),
                                        'configuration' => $config, 'requirements' => $rules->requirements($config, $values),
                                        'recovery_fields' => $safety->recoveryFields($config),
                                    ];
                                    $reports->push(IntakeSafetyReport::create([
                                        'horse_id' => $horse->id, 'intake_booking_id' => $booking->id, 'state' => $state,
                                    ]));
                                }
                            }
                        }
                    });
                    // No answer, attachment, booking or horse activity timestamp writes.
                    $safety->project($horse, touch: false);
                });
            }
        });
    }

    private function sameConcern(array $a, array $b): bool
    {
        if (! empty($a['episode_id']) && ($a['episode_id'] === ($b['episode_id'] ?? null))) {
            return true;
        }

        return ! empty($a['kind']) && ! empty($b['kind'])
            && ($a['booking_id'] ?? null) === ($b['booking_id'] ?? null)
            && $a['kind'] === $b['kind']
            && ($a['configuration'] ?? null) == ($b['configuration'] ?? null);
    }
}
