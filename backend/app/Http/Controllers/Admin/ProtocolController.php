<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveProtocolRequest;
use App\Models\Horse;
use App\Models\Protocol;
use App\Models\ProtocolAdvice;
use App\Models\ProtocolAnalysis;
use App\Models\ProtocolPhase;
use App\Models\ProtocolPhaseItem;
use App\Models\ProtocolTask;
use App\Models\ProtocolType;
use App\Models\ProtocolTypePhase;
use App\Models\Therapist;
use App\Support\AuditLogger;
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
                ->whereHas('protocolType', fn ($protocolType) => $protocolType->where('name', 'ilike', "%{$q}%"))
                ->orWhereHas('horse', fn ($horse) => $horse->where('name', 'ilike', "%{$q}%"))))
            ->with(
                'protocolType:id,name',
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
        $data = $this->withRequiredPhases($request->validated());

        $protocol = DB::transaction(function () use ($data) {
            $protocol = Protocol::query()->create($this->protocolAttributes($data));
            $this->syncStructure($protocol, $data);
            AuditLogger::created($protocol);

            return $protocol;
        });

        return to_route('admin.protocols.edit', $protocol)
            ->with('success', 'Protocol created.');
    }

    public function edit(Protocol $protocol): Response
    {
        $protocol->load([
            'horse:id,name,owner_id,breed,age,sex,weight_kg,status',
            'horse.owner:id,name,email',
            'horse.focusTopics:id,title,slug',
            'therapist:id,name,title',
            'phases.items',
            'phases.phase:id,protocol_type_id,name,description,required,order',
            'phases.supplements.supplement:id,protocol_type_phase_id,name,description,supplement_type,add_by_default,max_aantal_in_fase,min_aantal_per_week,rust_periode_in_weken',
            'analysis.advice',
            'tasks',
        ]);

        return Inertia::render('Protocols/Edit', [
            'protocol' => $protocol,
            ...$this->editorOptions($protocol->horse_id),
        ]);
    }

    public function update(SaveProtocolRequest $request, Protocol $protocol): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $protocol) {
            $attributes = $this->protocolAttributes($data);
            $before = $protocol->only(array_keys($attributes));
            $protocol->update($attributes);
            $this->syncStructure($protocol, $data);
            AuditLogger::updated($protocol, $before);
        });

        return to_route('admin.protocols.edit', $protocol)
            ->with('success', 'Protocol saved.');
    }

    public function show(Protocol $protocol): Response
    {
        $protocol->load([
            'horse:id,name,owner_id', 'horse.owner:id,name',
            'therapist:id,name,title',
            'phases.items',
            'analysis',
            'tasks' => fn ($q) => $q->withCount('completions'),
        ]);

        return Inertia::render('Protocols/Show', ['protocol' => $protocol]);
    }

    public function updateStatus(Request $request, Protocol $protocol): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', 'in:active,paused,completed']])['status'];
        $before = $protocol->only('status');
        $protocol->update(['status' => $status]);
        AuditLogger::updated($protocol, $before, $request->input('reason'));

        return back()->with('success', "Protocol marked {$status}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function editorOptions(?string $selectedHorseId = null): array
    {
        return [
            'selectedHorseId' => $selectedHorseId ?: null,
            'horses' => Horse::query()
                ->where('status', 'active')
                ->with('owner:id,name,email', 'focusTopics:id,title,slug')
                ->orderBy('name')
                ->get(['id', 'owner_id', 'name', 'breed', 'age', 'sex', 'weight_kg', 'status']),
            'therapists' => Therapist::query()
                ->orderBy('name')
                ->get(['id', 'name', 'title']),
            'protocolTypes' => ProtocolType::query()
                ->with([
                    'phases:id,protocol_type_id,order,name,description,required',
                    'phases.weeks:id,protocol_type_phase_id,number',
                    'phases.supplements:id,protocol_type_phase_id,name,description,supplement_type,add_by_default,max_aantal_in_fase,min_aantal_per_week,rust_periode_in_weken',
                    'phases.supplements.weeks:id,number',
                ])
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function protocolAttributes(array $data): array
    {
        $attributes = Arr::only($data, [
            'horse_id',
            'protocol_type_id',
            'therapist_id',
            'title',
            'subtitle_analyse',
            'subtitle_protocol',
            'subtitle_calendar',
            'total_weeks',
            'current_week',
            'started_at',
            'status',
        ]);

        foreach ($attributes as $key => $value) {
            if ($value === '') {
                $attributes[$key] = null;
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncStructure(Protocol $protocol, array $data): void
    {
        $phaseIds = [];
        $phaseIdByClientKey = [];

        foreach ($data['phases'] as $order => $phaseData) {
            $phase = isset($phaseData['id'])
                ? $protocol->phases()->whereKey($phaseData['id'])->firstOrFail()
                : new ProtocolPhase(['protocol_id' => $protocol->id]);

            $phase->fill([
                'protocol_id' => $protocol->id,
                'protocol_type_phase_id' => $phaseData['protocol_type_phase_id'],
                'order' => $order,
                'title' => $phaseData['title'],
                'state' => $phaseData['state'],
                'week_start' => $this->nullableValue($phaseData['week_start'] ?? null),
                'week_end' => $this->nullableValue($phaseData['week_end'] ?? null),
                'chip_label' => $this->nullableValue($phaseData['chip_label'] ?? null),
            ])->save();

            $phaseIds[] = $phase->id;
            $phaseIdByClientKey[$phaseData['client_key']] = $phase->id;
            $this->syncPhaseItems($phase, $phaseData['items']);
            $this->syncPhaseSupplements($phase, $phaseData['supplement_ids']);
        }

        $protocol->phases()->whereNotIn('id', $phaseIds)->delete();
        $this->syncTasks($protocol, $data['tasks'], $phaseIdByClientKey);
        $this->syncAnalysis($protocol, $data['analysis']['cause'] ?? null, $data['advice']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withRequiredPhases(array $data): array
    {
        $definitions = ProtocolTypePhase::query()
            ->where('protocol_type_id', $data['protocol_type_id'])
            ->orderBy('order')
            ->get();
        $phases = collect($data['phases']);
        $selectedDefinitionIds = $phases->pluck('protocol_type_phase_id');
        $hasActivePhase = $phases->contains(fn (array $phase): bool => $phase['state'] === 'active');

        foreach ($definitions->where('required', true) as $definition) {
            if ($selectedDefinitionIds->contains($definition->id)) {
                continue;
            }

            $phases->push([
                'id' => null,
                'client_key' => 'required-'.$definition->id,
                'protocol_type_phase_id' => $definition->id,
                'title' => $definition->name,
                'state' => $hasActivePhase ? 'upcoming' : 'active',
                'week_start' => null,
                'week_end' => null,
                'chip_label' => 'Verplicht',
                'items' => [],
                'supplement_ids' => $definition->supplements()
                    ->where('add_by_default', true)
                    ->pluck('id')
                    ->all(),
            ]);
            $hasActivePhase = true;
        }

        $definitionOrder = $definitions->pluck('order', 'id');
        $data['phases'] = $phases
            ->sortBy(fn (array $phase) => $definitionOrder[$phase['protocol_type_phase_id']] ?? PHP_INT_MAX)
            ->values()
            ->all();

        return $data;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncPhaseItems(ProtocolPhase $phase, array $items): void
    {
        $itemIds = [];

        foreach ($items as $order => $itemData) {
            $item = isset($itemData['id'])
                ? $phase->items()->whereKey($itemData['id'])->firstOrFail()
                : new ProtocolPhaseItem(['phase_id' => $phase->id]);

            $item->fill([
                'phase_id' => $phase->id,
                'order' => $order,
                'label' => $itemData['label'],
            ])->save();
            $itemIds[] = $item->id;
        }

        $phase->items()->when($itemIds, fn ($query) => $query->whereNotIn('id', $itemIds))->delete();
        if ($itemIds === []) {
            $phase->items()->delete();
        }
    }

    /**
     * @param  array<int, string>  $supplementIds
     */
    private function syncPhaseSupplements(ProtocolPhase $phase, array $supplementIds): void
    {
        foreach ($supplementIds as $supplementId) {
            $phase->supplements()->firstOrCreate([
                'supplement_id' => $supplementId,
            ]);
        }

        $phase->supplements()
            ->when($supplementIds, fn ($query) => $query->whereNotIn('supplement_id', $supplementIds))
            ->delete();

        if ($supplementIds === []) {
            $phase->supplements()->delete();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @param  array<string, string>  $phaseIdByClientKey
     */
    private function syncTasks(Protocol $protocol, array $tasks, array $phaseIdByClientKey): void
    {
        $taskIds = [];

        foreach ($tasks as $order => $taskData) {
            $task = isset($taskData['id'])
                ? $protocol->tasks()->whereKey($taskData['id'])->firstOrFail()
                : new ProtocolTask(['protocol_id' => $protocol->id]);

            $task->fill([
                'protocol_id' => $protocol->id,
                'phase_id' => $phaseIdByClientKey[$taskData['phase_key'] ?? ''] ?? null,
                'label' => $taskData['label'],
                'meta' => $this->nullableValue($taskData['meta'] ?? null),
                'kind' => $taskData['kind'],
                'order' => $order,
                'active_from' => $this->nullableValue($taskData['active_from'] ?? null),
                'active_until' => $this->nullableValue($taskData['active_until'] ?? null),
                'reference_item_id' => $this->nullableValue($taskData['reference_item_id'] ?? null),
            ])->save();
            $taskIds[] = $task->id;
        }

        $protocol->tasks()->when($taskIds, fn ($query) => $query->whereNotIn('id', $taskIds))->delete();
        if ($taskIds === []) {
            $protocol->tasks()->delete();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $adviceRows
     */
    private function syncAnalysis(Protocol $protocol, mixed $cause, array $adviceRows): void
    {
        $cause = trim((string) ($cause ?? ''));
        $analysis = $protocol->analysis()->first();

        if ($cause === '' && $adviceRows === []) {
            $analysis?->delete();

            return;
        }

        $analysis ??= new ProtocolAnalysis(['protocol_id' => $protocol->id]);
        $analysis->fill(['protocol_id' => $protocol->id, 'cause' => $cause])->save();
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

    private function nullableValue(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
