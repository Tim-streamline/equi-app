<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\LibraryItem;
use App\Models\Protocol;
use App\Models\ProtocolAnalysis;
use App\Models\ProtocolPhase;
use App\Models\ProtocolPhaseSupplement;
use App\Models\ProtocolPhaseSupplementWeek;
use App\Models\ProtocolPhaseWeek;
use App\Models\ProtocolTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/** Build an unsaved relation graph for the same formatter used by the customer dashboard. */
class ProtocolPreview
{
    public function build(array $data, ?Protocol $stored, int $week = 1): array
    {
        $horse = Horse::findOrFail($data['horse_id']);
        $template = ProtocolTemplate::with('phases.supplements')->findOrFail($data['protocol_template_id']);
        $stored?->load('phases.supplements', 'analysis', 'voedingAdviezen', 'managementAdviezen', 'bewegingAdviezen');
        $protocol = new Protocol($data);
        $protocol->id = $stored?->id ?? (string) Str::uuid();
        $protocol->setRelation('horse', $horse);
        $nutrition = app(ProtocolNutrition::class);
        $answers = $nutrition->answers($horse);
        $weight = $nutrition->actualWeight($protocol, $answers);
        $phases = new Collection;
        $previousStart = null;
        $previousEnd = null;
        $latestEnd = 0;
        foreach ($data['phases'] as $index => $row) {
            $definition = $template->phases->firstWhere('id', $row['protocol_template_phase_id']);
            $snapshot = $stored?->phases->firstWhere('id', $row['id'] ?? null);
            $phase = new ProtocolPhase(($snapshot ?? $definition)->only(['description', 'required']));
            $phase->id = $snapshot?->id ?? (string) Str::uuid();
            $phase->title = $snapshot?->title ?? $definition->name;
            $delay = $row['start_after_previous_phase_weeks'] ?? null;
            $start = $index === 0 ? 1 : ($delay !== null && $previousStart !== null ? $previousStart + $delay : ($previousEnd ?? $latestEnd) + 1);
            $weeks = new Collection;
            for ($n = 1; $n <= $row['week_count']; $n++) {
                $phaseWeek = new ProtocolPhaseWeek(['number' => $n, 'protocol_week_number' => $start + $n - 1]);
                $phaseWeek->id = (string) Str::uuid();
                $weeks->push($phaseWeek);
            }
            $phase->setRelation('weeks', $weeks);
            $previousStart = $weeks->isEmpty() ? null : $start;
            $previousEnd = $weeks->isEmpty() ? null : $start + $weeks->count() - 1;
            $latestEnd = max($latestEnd, $previousEnd ?? 0);
            $supplements = new Collection;
            foreach ($row['supplements'] as $selection) {
                $source = $snapshot?->supplements->firstWhere('id', $selection['id'] ?? null)
                    ?? $definition->supplements->firstWhere('id', $selection['supplement_id']);
                $item = new ProtocolPhaseSupplement($source->only(['name', 'description', 'supplement_type', 'dosis_type', 'dosis', 'unit']));
                $item->id = $selection['id'] ?? (string) Str::uuid();
                $item->dosage = ($selection['dosage_mode'] ?? 'manual') === 'automatic'
                    ? app(ProtocolDosage::class)->calculate($item, $weight) : ($selection['dosage'] ?? null);
                $item->instructions = $selection['instructions'] ?? null;
                $item->aantal_per_week = $selection['aantal_per_week'] ?? null;
                $item->setRelation('weeks', new Collection($weeks->whereIn('number', $selection['week_numbers'])->map(
                    fn ($w) => new ProtocolPhaseSupplementWeek(['protocol_phase_week_id' => $w->id]),
                )->all()));
                $supplements->push($item);
            }
            $phase->setRelation('supplements', $supplements);
            $phases->push($phase);
        }
        $protocol->setRelation('phases', $phases);
        $protocol->total_weeks = $latestEnd;
        $analysis = new ProtocolAnalysis(($data['analysis'] ?? []) + ['focus_points' => $stored?->analysis?->focus_points ?? []]);
        $analysis->setRelation('advice', new Collection);
        $protocol->setRelation('analysis', $analysis);
        foreach (['voeding', 'management', 'beweging'] as $category) {
            $relation = $category.'Adviezen';
            $class = 'App\\Models\\'.ucfirst($category).'Advies';
            $snapshotClass = 'App\\Models\\Protocol'.ucfirst($category).'Advies';
            $foreign = $category.'_advies_id';
            $items = new Collection;
            foreach ($class::whereIn('id', $data[$category.'_advies_ids'])->orderBy('title')->get() as $source) {
                $snapshot = $stored?->{$relation}->firstWhere($foreign, $source->id);
                $item = new $snapshotClass(($snapshot ?? $source)->only(['title', 'description', 'layout']));
                $item->id = $snapshot?->id ?? $source->id;
                $item->{$foreign} = $source->id;
                $items->push($item);
            }
            $protocol->setRelation($relation, $items);
        }
        $start = $protocol->started_at ? CarbonImmutable::parse($protocol->started_at->toDateString(), 'Europe/Amsterdam') : CarbonImmutable::today('Europe/Amsterdam');
        $protocol->started_at = $start;
        $now = $start->addWeeks(max(0, min(max(1, $latestEnd), $week) - 1));

        $dashboard = app(HorseDashboard::class);
        $presentation = $dashboard->protocol($protocol, $now, answers: $answers, preview: true);
        $items = LibraryItem::whereNotNull('published_at')->where('published_at', '<=', now())->get();
        $presentation['nutrition'] += $dashboard->nutritionLinks($protocol, $items, fn ($item) => [
            'id' => $item->id, 'title' => $item->title, 'heroImageUrl' => $item->hero_image_url,
        ]);

        return ['horse' => ['id' => $horse->id, 'name' => $horse->name], 'protocol' => $presentation];
    }
}
