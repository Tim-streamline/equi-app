<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SupplementDoseType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveProtocolRequest;
use App\Models\BewegingAdvies;
use App\Models\Horse;
use App\Models\LibraryItem;
use App\Models\ManagementAdvies;
use App\Models\Protocol;
use App\Models\ProtocolAdvice;
use App\Models\ProtocolAnalysis;
use App\Models\ProtocolPhase;
use App\Models\ProtocolPhaseSupplement;
use App\Models\ProtocolTemplate;
use App\Models\ProtocolTemplatePhase;
use App\Models\Supplement;
use App\Models\Therapist;
use App\Models\VoedingAdvies;
use App\Support\AuditLogger;
use App\Support\ProtocolNutrition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProtocolController extends Controller
{
    public function index(Request $request): Response
    {
        $protocols = Protocol::query()
            ->when($request->string('status')->toString(), fn ($query, $s) => $query->where('status', $s))
            ->when($request->string('q')->toString(), fn ($query, $q) => $query->where(fn ($search) => $search
                ->where('protocol_template_name', 'ilike', "%{$q}%")
                ->orWhereHas('horse', fn ($horse) => $horse->where('name', 'ilike', "%{$q}%"))))
            ->with(
                'protocolTemplate:id,name',
                'horse:id,name,owner_id',
                'horse.owner:id,name',
                'therapist:id,name',
                'currentPhase:id,protocol_id,title,state,order',
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Protocols/Index', [
            'protocols' => $protocols,
            'filters' => $request->only('status', 'q'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Protocols/Edit', [
            'protocol' => null,
            ...$this->editorOptions($request->string('horse_id')->toString()),
        ]);
    }

    public function store(SaveProtocolRequest $request): RedirectResponse
    {
        $protocolTemplate = ProtocolTemplate::query()->findOrFail($request->validated('protocol_template_id'));
        $data = $this->withRequiredPhases($request->validated());

        $protocol = DB::transaction(function () use ($data, $protocolTemplate) {
            $protocol = Protocol::query()->create($this->protocolAttributes($data, $protocolTemplate));
            $this->syncStructure($protocol, $data);
            AuditLogger::created($protocol);

            return $protocol;
        });

        return to_route('admin.protocols.edit', $protocol)
            ->with('success', 'Protocol aangemaakt.');
    }

    public function edit(Protocol $protocol): Response
    {
        $this->loadEditorRelations($protocol);

        return Inertia::render('Protocols/Edit', [
            'protocol' => $protocol,
            'weeklyUpdates' => DB::table('protocol_weekly_updates')->where('protocol_id', $protocol->id)->orderByDesc('week_number')->get(),
            ...$this->editorOptions($protocol->horse_id),
        ]);
    }

    public function update(SaveProtocolRequest $request, Protocol $protocol): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $protocol) {
            $attributes = $this->protocolAttributes($data);
            if ($data['published'] && $protocol->published_at) {
                $attributes['published_at'] = $protocol->published_at;
            }
            $before = $protocol->only(array_keys($attributes));
            $protocol->update($attributes);
            $this->syncStructure($protocol, $data);
            AuditLogger::updated($protocol, $before);
        });

        return to_route('admin.protocols.edit', $protocol)
            ->with('success', 'Protocol opgeslagen.');
    }

    public function show(Protocol $protocol): Response
    {
        $protocol->load([
            'horse:id,name,owner_id', 'horse.owner:id,name',
            'therapist:id,name,title',
            'phases.weeks',
            'phases.supplements.weeks.protocolPhaseWeek',
            'analysis',
            'voedingAdviezen',
            'managementAdviezen',
            'bewegingAdviezen',
        ]);

        return Inertia::render('Protocols/Show', ['protocol' => $protocol]);
    }

    public function updateStatus(Request $request, Protocol $protocol): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', 'in:active,paused,completed']])['status'];
        $before = $protocol->only('status');
        $protocol->update(['status' => $status]);
        $this->synchronizeProtocolTiming($protocol);
        AuditLogger::updated($protocol, $before, $request->input('reason'));

        return back()->with('success', "Protocol gemarkeerd als {$status}.");
    }

    /** @return array<string, mixed> */
    private function editorOptions(?string $selectedHorseId = null): array
    {
        return [
            'selectedHorseId' => $selectedHorseId ?: null,
            'libraryItems' => LibraryItem::query()->whereNotNull('published_at')->orderBy('title')->get(['id', 'title']),
            'intakeFeeds' => Horse::query()->where('status', 'active')->get()->mapWithKeys(function ($horse) {
                $nutrition = app(ProtocolNutrition::class);

                return [$horse->id => $nutrition->forProtocol(new Protocol, $nutrition->answers($horse))['feeds']];
            }),
            'horses' => Horse::query()
                ->where('status', 'active')
                ->with('owner:id,name,email')
                ->orderBy('name')
                ->get(['id', 'owner_id', 'name', 'breed', 'age', 'sex', 'weight_kg', 'status']),
            'therapists' => Therapist::query()
                ->orderBy('name')
                ->get(['id', 'name', 'title']),
            'voedingAdviezen' => VoedingAdvies::query()->orderBy('title')->get(),
            'managementAdviezen' => ManagementAdvies::query()->orderBy('title')->get(),
            'bewegingAdviezen' => BewegingAdvies::query()->orderBy('title')->get(),
            'protocolTemplates' => ProtocolTemplate::query()
                ->with([
                    'phases:id,protocol_template_id,order,name,description,required,start_after_previous_phase_weeks',
                    'phases.weeks:id,protocol_template_phase_id,number',
                    'phases.supplements:id,protocol_template_phase_id,name,description,instructions,supplement_type,dosis_type,dosis,unit,add_by_default,max_aantal_in_fase,min_aantal_per_week,rust_periode_in_weken',
                    'phases.supplements.weeks:id,number',
                ])
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    private function loadEditorRelations(Protocol $protocol): void
    {
        $protocol->load([
            'protocolTemplate:id,name',
            'horse:id,name,owner_id,breed,age,sex,weight_kg,status',
            'horse.owner:id,name,email',
            'therapist:id,name,title',
            'phases.weeks',
            'phases.supplements.weeks.protocolPhaseWeek',
            'analysis.advice',
            'voedingAdviezen',
            'managementAdviezen',
            'bewegingAdviezen',
        ]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function protocolAttributes(array $data, ?ProtocolTemplate $protocolTemplate = null): array
    {
        $attributes = Arr::only($data, [
            'horse_id',
            'protocol_template_id',
            'therapist_id',
            'title',
            'started_at',
            'status',
            'customer_settings',
        ]);
        $attributes['published_at'] = $data['published'] ? now() : null;

        if ($protocolTemplate) {
            $attributes['protocol_template_name'] = $protocolTemplate->name;
        }

        foreach ($attributes as $key => $value) {
            if ($value === '') {
                $attributes[$key] = null;
            }
        }

        return $attributes;
    }

    /** @param array<string, mixed> $data */
    private function syncStructure(Protocol $protocol, array $data): void
    {
        $phaseIds = [];
        $phases = collect($data['phases'])->values();

        foreach ($phases as $order => $phaseData) {
            $isExisting = filled($phaseData['id'] ?? null);
            $phase = $isExisting
                ? $protocol->phases()->whereKey($phaseData['id'])->firstOrFail()
                : new ProtocolPhase(['protocol_id' => $protocol->id]);
            $attributes = [
                'protocol_id' => $protocol->id,
                'order' => $order,
                'state' => 'upcoming',
                'week_start' => null,
                'week_end' => null,
                'chip_label' => null,
            ];

            if (! $isExisting) {
                $phaseDefinition = ProtocolTemplatePhase::query()
                    ->where('protocol_template_id', $protocol->protocol_template_id)
                    ->findOrFail($phaseData['protocol_template_phase_id']);
                $attributes += [
                    'protocol_template_phase_id' => $phaseDefinition->id,
                    'title' => $phaseDefinition->name,
                    'description' => $phaseDefinition->description,
                    'required' => $phaseDefinition->required,
                    'start_after_previous_phase_weeks' => $phaseDefinition->start_after_previous_phase_weeks,
                ];
            }

            if (array_key_exists('start_after_previous_phase_weeks', $phaseData)) {
                $attributes['start_after_previous_phase_weeks'] = $phaseData['start_after_previous_phase_weeks'];
            }

            $phase->fill($attributes)->save();

            $phaseIds[] = $phase->id;
            $this->syncPhaseWeeks($phase, (int) $phaseData['week_count']);
            $this->syncPhaseSupplements($phase, $phaseData['supplements']);
        }

        $protocol->phases()->whereNotIn('id', $phaseIds)->delete();
        $this->syncAnalysis($protocol, $data['analysis'] ?? [], $data['advice']);
        $this->syncAdviceSelections($protocol, $data['voeding_advies_ids'], 'voedingAdviezen', VoedingAdvies::class, 'voeding_advies_id');
        $this->syncAdviceSelections($protocol, $data['management_advies_ids'], 'managementAdviezen', ManagementAdvies::class, 'management_advies_id');
        $this->syncAdviceSelections($protocol, $data['beweging_advies_ids'], 'bewegingAdviezen', BewegingAdvies::class, 'beweging_advies_id');
        $this->synchronizeProtocolTiming($protocol);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withRequiredPhases(array $data): array
    {
        $horseWeightKg = Horse::query()->whereKey($data['horse_id'])->value('weight_kg');
        $definitions = ProtocolTemplatePhase::query()
            ->where('protocol_template_id', $data['protocol_template_id'])
            ->with('weeks', 'supplements.weeks')
            ->orderBy('order')
            ->get();
        $phases = collect($data['phases']);
        $selectedDefinitionIds = $phases->pluck('protocol_template_phase_id');

        foreach ($definitions->where('required', true) as $definition) {
            if ($selectedDefinitionIds->contains($definition->id)) {
                continue;
            }

            $phases->push($this->phasePayloadFromDefinition($definition, $horseWeightKg));
        }

        $definitionOrder = $definitions->pluck('order', 'id');
        $data['phases'] = $phases
            ->sortBy(fn (array $phase) => $definitionOrder[$phase['protocol_template_phase_id']] ?? PHP_INT_MAX)
            ->values()
            ->all();

        return $data;
    }

    /** @return array<string, mixed> */
    private function phasePayloadFromDefinition(ProtocolTemplatePhase $definition, int|float|null $horseWeightKg): array
    {
        return [
            'id' => null,
            'client_key' => 'required-'.$definition->id,
            'protocol_template_phase_id' => $definition->id,
            'title' => $definition->name,
            'description' => $definition->description,
            'required' => $definition->required,
            'start_after_previous_phase_weeks' => $definition->start_after_previous_phase_weeks,
            'week_count' => $definition->weeks->count(),
            'supplements' => $definition->supplements
                ->where('add_by_default', true)
                ->map(fn ($supplement): array => [
                    'id' => null,
                    'supplement_id' => $supplement->id,
                    'dosage' => $this->templateDosage($supplement, $horseWeightKg),
                    'aantal_per_week' => $supplement->min_aantal_per_week,
                    'instructions' => $supplement->instructions,
                    'week_numbers' => $supplement->weeks->pluck('number')->values()->all(),
                ])->values()->all(),
        ];
    }

    private function syncPhaseWeeks(ProtocolPhase $phase, int $weekCount): void
    {
        $templateWeeks = $phase->phase()->firstOrFail()->weeks()->get()->keyBy('number');
        $weekIds = [];

        for ($number = 1; $number <= $weekCount; $number++) {
            $week = $phase->weeks()->firstOrNew(['number' => $number]);
            if (! $week->exists) {
                $week->protocol_template_phase_week_id = $templateWeeks->get($number)?->id;
            }
            $week->protocol_week_number = $number;
            $week->save();
            $weekIds[] = $week->id;
        }

        $phase->weeks()->when($weekIds, fn ($query) => $query->whereNotIn('id', $weekIds))->delete();
        if ($weekIds === []) {
            $phase->weeks()->delete();
        }
    }

    /** @param array<int, array<string, mixed>> $supplements */
    private function syncPhaseSupplements(ProtocolPhase $phase, array $supplements): void
    {
        $selectionIds = [];

        foreach ($supplements as $supplementData) {
            $isExisting = filled($supplementData['id'] ?? null);
            $selection = $isExisting
                ? $phase->supplements()->whereKey($supplementData['id'])->firstOrFail()
                : $phase->supplements()->firstOrNew([
                    'supplement_id' => $supplementData['supplement_id'],
                ]);
            $catalogSupplement = ! $isExisting && filled($supplementData['supplement_id'] ?? null)
                ? $phase->phase()->firstOrFail()->supplements()->whereKey($supplementData['supplement_id'])->firstOrFail()
                : null;

            $attributes = [
                'protocol_phase_id' => $phase->id,
                'dosage' => $this->nullableValue($supplementData['dosage'] ?? null),
                'aantal_per_week' => $supplementData['aantal_per_week'] ?? null,
                'instructions' => $this->nullableValue($supplementData['instructions'] ?? null),
            ];
            if ($catalogSupplement) {
                $attributes += [
                    'supplement_id' => $catalogSupplement->id,
                    'name' => $catalogSupplement->name,
                    'description' => $catalogSupplement->description,
                    'supplement_type' => $catalogSupplement->supplement_type->value,
                    'dosis_type' => $catalogSupplement->dosis_type?->value,
                    'dosis' => $catalogSupplement->dosis,
                    'unit' => $catalogSupplement->unit?->value,
                    'add_by_default' => $catalogSupplement->add_by_default,
                    'max_aantal_in_fase' => $catalogSupplement->max_aantal_in_fase,
                    'min_aantal_per_week' => $catalogSupplement->min_aantal_per_week,
                    'rust_periode_in_weken' => $catalogSupplement->rust_periode_in_weken,
                ];
            }
            $selection->fill($attributes)->save();
            $selectionIds[] = $selection->id;
            $this->syncProtocolSupplementWeeks($selection, $phase, $supplementData['week_numbers']);
        }

        $phase->supplements()->when($selectionIds, fn ($query) => $query->whereNotIn('id', $selectionIds))->delete();
        if ($selectionIds === []) {
            $phase->supplements()->delete();
        }
    }

    /** @param array<int, int> $weekNumbers */
    private function syncProtocolSupplementWeeks(
        ProtocolPhaseSupplement $selection,
        ProtocolPhase $phase,
        array $weekNumbers,
    ): void {
        $phaseWeeks = $phase->weeks()->whereIn('number', $weekNumbers)->get()->keyBy('number');
        $selectionWeekIds = [];

        foreach ($weekNumbers as $weekNumber) {
            $phaseWeek = $phaseWeeks->get((int) $weekNumber);
            if (! $phaseWeek) {
                continue;
            }

            $selectionWeek = $selection->weeks()->firstOrCreate([
                'protocol_phase_week_id' => $phaseWeek->id,
            ]);
            $selectionWeekIds[] = $selectionWeek->id;
        }

        $selection->weeks()->when($selectionWeekIds, fn ($query) => $query->whereNotIn('id', $selectionWeekIds))->delete();
        if ($selectionWeekIds === []) {
            $selection->weeks()->delete();
        }
    }

    /** @param array<int, array<string, mixed>> $adviceRows */
    private function syncAnalysis(Protocol $protocol, array $content, array $adviceRows): void
    {
        $cause = trim((string) ($content['cause'] ?? ''));
        $analysis = $protocol->analysis()->first();
        // Older clients may omit the new fields; do not erase previously authored content.
        $summary = array_key_exists('summary', $content) ? $content['summary'] : $analysis?->summary;
        $focus = $content['focus_points'] ?? $analysis?->focus_points ?? [];
        $observations = $content['observations'] ?? $analysis?->observations ?? [];

        if ($cause === '' && $adviceRows === [] && blank($summary) && $focus === [] && $observations === []) {
            $analysis?->delete();

            return;
        }

        $analysis ??= new ProtocolAnalysis(['protocol_id' => $protocol->id]);
        $analysis->fill(['protocol_id' => $protocol->id, 'cause' => $cause, 'summary' => $summary,
            'focus_points' => $focus, 'observations' => $observations])->save();
        $adviceIds = [];

        foreach ($adviceRows as $order => $adviceData) {
            $advice = isset($adviceData['id'])
                ? $analysis->advice()->whereKey($adviceData['id'])->firstOrFail()
                : new ProtocolAdvice(['analysis_id' => $analysis->id]);

            $advice->fill([
                'analysis_id' => $analysis->id,
                'icon_key' => $adviceData['icon_key'],
                'title' => $adviceData['title'],
                'body' => $adviceData['body'],
                'order' => $order,
            ])->save();
            $adviceIds[] = $advice->id;
        }

        $analysis->advice()->when($adviceIds, fn ($query) => $query->whereNotIn('id', $adviceIds))->delete();
        if ($adviceIds === []) {
            $analysis->advice()->delete();
        }
    }

    /**
     * @param  array<int, string>  $sourceIds
     * @param  class-string<VoedingAdvies|ManagementAdvies|BewegingAdvies>  $sourceModel
     */
    private function syncAdviceSelections(
        Protocol $protocol,
        array $sourceIds,
        string $relation,
        string $sourceModel,
        string $sourceForeignKey,
    ): void {
        $sourceIds = collect($sourceIds)->unique()->values();
        $snapshots = $protocol->{$relation}()->get()->keyBy($sourceForeignKey);
        $snapshotIds = [];

        foreach ($sourceIds as $sourceId) {
            $snapshot = $snapshots->get($sourceId);

            if (! $snapshot) {
                $source = $sourceModel::query()->findOrFail($sourceId);
                $snapshot = $protocol->{$relation}()->create([
                    $sourceForeignKey => $source->id,
                    'title' => $source->title,
                    'description' => $source->description,
                    'layout' => $source->layout,
                ]);
            }

            $snapshotIds[] = $snapshot->id;
        }

        $protocol->{$relation}()
            ->when($snapshotIds, fn ($query) => $query->whereNotIn('id', $snapshotIds))
            ->delete();
    }

    private function synchronizeProtocolTiming(Protocol $protocol): void
    {
        $protocol->load('phases.weeks', 'horse');
        $previousWeekStart = null;
        $previousWeekEnd = null;
        $latestWeekEnd = 0;

        foreach ($protocol->phases as $phaseIndex => $phase) {
            $phaseWeekStart = match (true) {
                $phaseIndex === 0 => 1,
                $phase->start_after_previous_phase_weeks !== null && $previousWeekStart !== null => $previousWeekStart + $phase->start_after_previous_phase_weeks,
                $previousWeekEnd !== null => $previousWeekEnd + 1,
                default => $latestWeekEnd + 1,
            };

            foreach ($phase->weeks as $weekIndex => $week) {
                $week->update(['protocol_week_number' => $phaseWeekStart + $weekIndex]);
            }

            $previousWeekStart = $phase->weeks->isNotEmpty() ? $phaseWeekStart : null;
            $previousWeekEnd = $phase->weeks->isNotEmpty()
                ? $phaseWeekStart + $phase->weeks->count() - 1
                : null;
            $latestWeekEnd = max($latestWeekEnd, $previousWeekEnd ?? 0);
        }

        $totalWeeks = $latestWeekEnd;
        $currentWeek = $this->currentWeek($protocol, $totalWeeks);

        foreach ($protocol->phases as $phase) {
            $weekStart = $phase->weeks->first()?->protocol_week_number;
            $weekEnd = $phase->weeks->last()?->protocol_week_number;
            $state = match (true) {
                $weekStart === null => 'upcoming',
                $protocol->status === 'completed' => 'done',
                $weekEnd < $currentWeek => 'done',
                $weekStart <= $currentWeek && $weekEnd >= $currentWeek => 'active',
                default => 'upcoming',
            };
            $chip = match ($state) {
                'done' => 'Afgerond',
                'active' => "Actief · wk {$weekStart}–{$weekEnd}",
                default => $weekStart ? "Vanaf wk {$weekStart}" : 'Geen weken',
            };

            $phase->update([
                'state' => $state,
                'week_start' => $weekStart,
                'week_end' => $weekEnd,
                'chip_label' => $chip,
            ]);
        }

        $activePhase = $protocol->phases->firstWhere('state', 'active');
        $analysisParts = collect([$protocol->horse?->breed])->filter()->values();

        $protocol->update([
            'total_weeks' => $totalWeeks ?: null,
            'current_week' => $totalWeeks ? $currentWeek : null,
            'subtitle_analyse' => $analysisParts->join(' · ') ?: null,
            'subtitle_protocol' => $totalWeeks
                ? "Week {$currentWeek} van {$totalWeeks}".($activePhase ? " · {$activePhase->title} actief" : '')
                : 'Planning nog niet compleet',
            'subtitle_calendar' => $protocol->started_at?->locale('nl')->translatedFormat('F Y'),
        ]);
    }

    private function currentWeek(Protocol $protocol, int $totalWeeks): int
    {
        if ($totalWeeks < 1) {
            return 1;
        }

        if ($protocol->status === 'completed') {
            return $totalWeeks;
        }

        if ($protocol->status === 'paused' || ! $protocol->started_at) {
            return max(1, min($totalWeeks, (int) ($protocol->current_week ?: 1)));
        }

        $days = $protocol->started_at->startOfDay()->diffInDays(now()->startOfDay(), false);

        return max(1, min($totalWeeks, (int) floor(max(0, $days) / 7) + 1));
    }

    private function nullableValue(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    private function templateDosage(Supplement $supplement, int|float|null $horseWeightKg): ?string
    {
        if ($supplement->dosis === null || $supplement->dosis_type === null || $supplement->unit === null) {
            return null;
        }

        if (in_array($supplement->dosis_type, [SupplementDoseType::PerKilogram, SupplementDoseType::Per600Kilograms], true)) {
            if ($horseWeightKg === null || $horseWeightKg <= 0) {
                return null;
            }

            $dose = $supplement->dosis * $horseWeightKg;
            if ($supplement->dosis_type === SupplementDoseType::Per600Kilograms) {
                $dose /= 600;
            }
        } else {
            $dose = $supplement->dosis;
        }

        $amount = rtrim(rtrim(number_format($dose, 12, '.', ''), '0'), '.');

        return "{$amount} {$supplement->unit->value}";
    }
}
