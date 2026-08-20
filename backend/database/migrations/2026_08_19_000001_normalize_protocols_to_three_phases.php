<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $protocols = DB::table('protocols')
                ->get(['id', 'total_weeks', 'current_week']);

            foreach ($protocols as $protocol) {
                $phases = DB::table('protocol_phases')
                    ->where('protocol_id', $protocol->id)
                    ->orderBy('order')
                    ->get();

                if ($phases->count() > 3 && Str::contains(Str::lower($phases->first()->title), ['voorbereiding', 'preparation'])) {
                    $preparation = $phases->first();
                    $firstPhase = $phases[1];
                    $lastItemOrder = DB::table('protocol_phase_items')
                        ->where('phase_id', $firstPhase->id)
                        ->max('order');
                    $nextItemOrder = $lastItemOrder === null ? 0 : ((int) $lastItemOrder) + 1;
                    $preparationItems = DB::table('protocol_phase_items')
                        ->where('phase_id', $preparation->id)
                        ->orderBy('order')
                        ->get(['id']);

                    foreach ($preparationItems as $item) {
                        DB::table('protocol_phase_items')
                            ->where('id', $item->id)
                            ->update([
                                'phase_id' => $firstPhase->id,
                                'order' => $nextItemOrder++,
                                'updated_at' => now(),
                            ]);
                    }

                    DB::table('protocol_tasks')
                        ->where('phase_id', $preparation->id)
                        ->update(['phase_id' => $firstPhase->id, 'updated_at' => now()]);
                    DB::table('protocol_phases')->where('id', $preparation->id)->delete();
                    $phases = $phases->slice(1)->values();
                }

                if ($phases->count() > 3) {
                    $thirdPhase = $phases[2];
                    $extraPhases = $phases->slice(3);
                    $lastItemOrder = DB::table('protocol_phase_items')
                        ->where('phase_id', $thirdPhase->id)
                        ->max('order');
                    $nextItemOrder = $lastItemOrder === null ? 0 : ((int) $lastItemOrder) + 1;

                    foreach ($extraPhases as $extraPhase) {
                        $items = DB::table('protocol_phase_items')
                            ->where('phase_id', $extraPhase->id)
                            ->orderBy('order')
                            ->get(['id']);

                        foreach ($items as $item) {
                            DB::table('protocol_phase_items')
                                ->where('id', $item->id)
                                ->update([
                                    'phase_id' => $thirdPhase->id,
                                    'order' => $nextItemOrder++,
                                    'updated_at' => now(),
                                ]);
                        }

                        DB::table('protocol_tasks')
                            ->where('phase_id', $extraPhase->id)
                            ->update(['phase_id' => $thirdPhase->id, 'updated_at' => now()]);
                    }

                    $mergedWeekEnd = $phases->slice(2)
                        ->pluck('week_end')
                        ->filter(fn ($week) => $week !== null)
                        ->max();
                    $mergedStates = $phases->slice(2)->pluck('state');
                    $mergedState = $mergedStates->contains('active')
                        ? 'active'
                        : ($mergedStates->every(fn ($state) => $state === 'done') ? 'done' : $thirdPhase->state);

                    DB::table('protocol_phases')
                        ->where('id', $thirdPhase->id)
                        ->update([
                            'week_end' => $mergedWeekEnd ?? $thirdPhase->week_end,
                            'state' => $mergedState,
                            'updated_at' => now(),
                        ]);

                    DB::table('protocol_phases')
                        ->whereIn('id', $extraPhases->pluck('id'))
                        ->delete();

                    $phases = $phases->take(3);
                }

                if ($phases->count() < 3) {
                    $missingCount = 3 - $phases->count();
                    $lastWeek = max(0, (int) ($phases->pluck('week_end')->filter()->max() ?? 0));
                    $remainingWeeks = max($missingCount, (int) ($protocol->total_weeks ?? 0) - $lastWeek);
                    $baseLength = intdiv($remainingWeeks, $missingCount);
                    $remainder = $remainingWeeks % $missingCount;

                    for ($offset = 0; $offset < $missingCount; $offset++) {
                        $phaseNumber = $phases->count() + $offset + 1;
                        $weekStart = $lastWeek + 1;
                        $weekEnd = $weekStart + $baseLength + ($offset < $remainder ? 1 : 0) - 1;
                        $currentWeek = (int) ($protocol->current_week ?? 1);
                        $state = $currentWeek > $weekEnd
                            ? 'done'
                            : ($currentWeek >= $weekStart ? 'active' : 'upcoming');

                        DB::table('protocol_phases')->insert([
                            'id' => (string) Str::uuid(),
                            'protocol_id' => $protocol->id,
                            'order' => $phaseNumber - 1,
                            'title' => "Fase {$phaseNumber}",
                            'state' => $state,
                            'week_start' => $weekStart,
                            'week_end' => $weekEnd,
                            'chip_label' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $lastWeek = $weekEnd;
                    }
                }

                DB::table('protocol_phases')
                    ->where('protocol_id', $protocol->id)
                    ->orderBy('order')
                    ->get(['id'])
                    ->each(fn ($phase, $order) => DB::table('protocol_phases')
                        ->where('id', $phase->id)
                        ->update(['order' => $order]));
            }
        });
    }

    public function down(): void
    {
        // The former phase count cannot be reconstructed without inventing data.
    }
};
