<?php

namespace App\Support;

use App\Models\ProtocolTemplate;
use Illuminate\Support\Arr;
use RuntimeException;

/** Builds the tracked catalog file (database/data/protocol-templates.json) read by ProtocolTemplateSeeder. */
class ProtocolTemplateExport
{
    public const SUPPLEMENT_FIELDS = [
        'name', 'description', 'instructions', 'product_url', 'administrations_per_day', 'supplement_type', 'dosis_type', 'dosis',
        'unit', 'add_by_default', 'max_aantal_in_fase', 'min_aantal_per_week', 'rust_periode_in_weken',
    ];

    public function catalog(): array
    {
        $templates = ProtocolTemplate::query()
            ->with(['phases.weeks', 'phases.supplements.weeks', 'phases.supplements.doseBuildup'])
            ->orderBy('name')
            ->get();

        if ($templates->isEmpty()) {
            throw new RuntimeException('No protocol templates found. Refusing to export an empty catalog.');
        }

        foreach ($templates as $template) {
            if ($template->phases->pluck('order')->duplicates()->isNotEmpty()) {
                throw new RuntimeException("Duplicate phase orders in {$template->name}.");
            }

            foreach ($template->phases as $phase) {
                if ($phase->supplements->pluck('name')->duplicates()->isNotEmpty()) {
                    throw new RuntimeException("Duplicate supplement names in {$template->name}: {$phase->name}.");
                }

                foreach ($phase->supplements as $supplement) {
                    if ($supplement->weeks->pluck('id')->diff($phase->weeks->pluck('id'))->isNotEmpty()) {
                        throw new RuntimeException("Supplement {$supplement->name} has weeks outside its phase.");
                    }
                }
            }
        }

        return $templates->map(fn ($template) => [
            'name' => $template->name,
            'phases' => $template->phases->map(fn ($phase) => [
                ...$phase->only(['order', 'name', 'description', 'required', 'start_after_previous_phase_weeks']),
                'weeks' => $phase->weeks->pluck('number')->all(),
                'supplements' => $phase->supplements->map(fn ($supplement) => [
                    ...Arr::only($supplement->getRawOriginal(), self::SUPPLEMENT_FIELDS),
                    'dose_buildup' => $supplement->doseBuildup->snapshot(),
                    'weeks' => $supplement->weeks->pluck('number')->all(),
                ])->all(),
            ])->all(),
        ])->all();
    }
}
