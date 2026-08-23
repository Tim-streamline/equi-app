<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SupplementDoseType;
use App\Enums\SupplementDoseUnit;
use App\Enums\SupplementType;
use App\Http\Controllers\Controller;
use App\Models\ProtocolTemplate;
use App\Models\ProtocolTemplatePhase;
use App\Models\ProtocolTemplatePhaseWeek;
use App\Models\Supplement;
use App\Models\SupplementWeek;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProtocolSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('ProtocolSettings/Index', [
            'protocolTemplates' => ProtocolTemplate::query()
                ->with([
                    'phases' => fn ($query) => $query->orderBy('order'),
                    'phases.weeks' => fn ($query) => $query->orderBy('number'),
                    'phases.supplements' => fn ($query) => $query->orderBy('name'),
                    'phases.supplements.weeks' => fn ($query) => $query->orderBy('number'),
                ])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $data = $this->validateTemplate($request);
        $protocolTemplate = ProtocolTemplate::create($data);
        AuditLogger::created($protocolTemplate);

        return back()->with('success', 'Protocol template created.');
    }

    public function updateTemplate(Request $request, ProtocolTemplate $protocolTemplate): RedirectResponse
    {
        $data = $this->validateTemplate($request, $protocolTemplate);
        $before = $protocolTemplate->only(array_keys($data));
        $protocolTemplate->update($data);
        AuditLogger::updated($protocolTemplate, $before);

        return back()->with('success', 'Protocol template updated.');
    }

    public function destroyTemplate(ProtocolTemplate $protocolTemplate): RedirectResponse
    {
        if ($protocolTemplate->protocols()->exists()) {
            return back()->with('error', 'Deze protocoltemplate is gekoppeld aan een of meer protocollen en kan niet worden verwijderd.');
        }

        AuditLogger::deleted($protocolTemplate);
        $protocolTemplate->delete();

        return back()->with('success', 'Protocol template removed.');
    }

    public function storePhase(Request $request): RedirectResponse
    {
        $data = $this->validatePhase($request);

        $phase = DB::transaction(function () use ($data) {
            $protocolTemplate = ProtocolTemplate::query()->lockForUpdate()->findOrFail($data['protocol_template_id']);
            $data['order'] = ((int) $protocolTemplate->phases()->max('order')) + 1;

            return ProtocolTemplatePhase::create($data);
        });

        AuditLogger::created($phase);

        return back()->with('success', 'Phase created.');
    }

    public function updatePhase(Request $request, ProtocolTemplatePhase $protocolTemplatePhase): RedirectResponse
    {
        $data = $this->validatePhase($request);
        $oldTemplateId = $protocolTemplatePhase->protocol_template_id;

        if ($data['protocol_template_id'] !== $oldTemplateId && $protocolTemplatePhase->protocolPhases()->exists()) {
            return back()->with('error', 'Een gebruikte fase kan niet naar een andere protocoltemplate worden verplaatst.');
        }

        DB::transaction(function () use ($data, $oldTemplateId, $protocolTemplatePhase) {
            $before = $protocolTemplatePhase->only([
                'protocol_template_id',
                'name',
                'description',
                'required',
                'start_after_previous_phase_weeks',
                'order',
            ]);

            if ($data['protocol_template_id'] !== $oldTemplateId) {
                ProtocolTemplate::query()->lockForUpdate()->whereKey([$oldTemplateId, $data['protocol_template_id']])->get();
                $data['order'] = ((int) ProtocolTemplatePhase::query()
                    ->where('protocol_template_id', $data['protocol_template_id'])
                    ->max('order')) + 1;
            }

            $protocolTemplatePhase->update($data);
            AuditLogger::updated($protocolTemplatePhase, $before);

            if ($data['protocol_template_id'] !== $oldTemplateId) {
                $this->renumberPhases($oldTemplateId);
            }
        });

        return back()->with('success', 'Phase updated.');
    }

    public function destroyPhase(ProtocolTemplatePhase $protocolTemplatePhase): RedirectResponse
    {
        if ($protocolTemplatePhase->protocolPhases()->exists()) {
            return back()->with('error', 'Deze fase is gekoppeld aan een of meer protocollen en kan niet worden verwijderd.');
        }

        DB::transaction(function () use ($protocolTemplatePhase) {
            $protocolTemplateId = $protocolTemplatePhase->protocol_template_id;
            AuditLogger::deleted($protocolTemplatePhase);
            $protocolTemplatePhase->delete();
            $this->renumberPhases($protocolTemplateId);
        });

        return back()->with('success', 'Phase removed.');
    }

    public function movePhase(Request $request, ProtocolTemplatePhase $protocolTemplatePhase): RedirectResponse
    {
        $direction = $request->validate([
            'direction' => ['required', Rule::in(['up', 'down'])],
        ])['direction'];

        $moved = DB::transaction(function () use ($direction, $protocolTemplatePhase): bool {
            $phase = ProtocolTemplatePhase::query()->lockForUpdate()->findOrFail($protocolTemplatePhase->id);
            $neighbor = ProtocolTemplatePhase::query()
                ->where('protocol_template_id', $phase->protocol_template_id)
                ->where('order', $direction === 'up' ? '<' : '>', $phase->order)
                ->orderBy('order', $direction === 'up' ? 'desc' : 'asc')
                ->lockForUpdate()
                ->first();

            if (! $neighbor) {
                return false;
            }

            $before = $phase->only('order');
            $phaseOrder = $phase->order;
            $phase->update(['order' => $neighbor->order]);
            $neighbor->update(['order' => $phaseOrder]);
            AuditLogger::updated($phase, $before);

            return true;
        });

        return back()->with('success', $moved ? 'Fasevolgorde bijgewerkt.' : 'De fase staat al op deze uiterste positie.');
    }

    public function storeWeek(ProtocolTemplatePhase $protocolTemplatePhase): RedirectResponse
    {
        $week = DB::transaction(function () use ($protocolTemplatePhase) {
            $phase = ProtocolTemplatePhase::query()->lockForUpdate()->findOrFail($protocolTemplatePhase->id);

            return $phase->weeks()->create([
                'number' => ((int) $phase->weeks()->max('number')) + 1,
            ]);
        });

        AuditLogger::created($week);

        return back()->with('success', 'Week added.');
    }

    public function destroyWeek(ProtocolTemplatePhaseWeek $protocolTemplatePhaseWeek): RedirectResponse
    {
        DB::transaction(function () use ($protocolTemplatePhaseWeek) {
            $phaseId = $protocolTemplatePhaseWeek->protocol_template_phase_id;
            ProtocolTemplatePhase::query()->lockForUpdate()->findOrFail($phaseId);
            AuditLogger::deleted($protocolTemplatePhaseWeek);
            $protocolTemplatePhaseWeek->delete();

            ProtocolTemplatePhaseWeek::query()
                ->where('protocol_template_phase_id', $phaseId)
                ->orderBy('number')
                ->get()
                ->each(fn (ProtocolTemplatePhaseWeek $week, int $index) => $week->update(['number' => $index + 1]));
        });

        return back()->with('success', 'Week removed.');
    }

    public function storeSupplement(Request $request): RedirectResponse
    {
        $supplement = Supplement::create($this->validateSupplement($request, true));
        AuditLogger::created($supplement);

        return back()->with('success', 'Supplement created.');
    }

    public function updateSupplement(Request $request, Supplement $supplement): RedirectResponse
    {
        $data = $this->validateSupplement($request);

        if (($data['protocol_template_phase_id'] ?? $supplement->protocol_template_phase_id) !== $supplement->protocol_template_phase_id
            && $supplement->protocolPhaseSupplements()->exists()) {
            return back()->with('error', 'Een gebruikt supplement kan niet naar een andere fase worden verplaatst.');
        }

        $before = $supplement->only(array_keys($data));
        $supplement->update($data);

        if ($supplement->wasChanged('protocol_template_phase_id')) {
            $supplement->weeks()->detach();
        }

        AuditLogger::updated($supplement, $before);

        return back()->with('success', 'Supplement updated.');
    }

    public function destroySupplement(Supplement $supplement): RedirectResponse
    {
        AuditLogger::deleted($supplement);
        $supplement->delete();

        return back()->with('success', 'Supplement removed.');
    }

    public function storeSupplementWeek(
        Supplement $supplement,
        ProtocolTemplatePhaseWeek $protocolTemplatePhaseWeek,
    ): RedirectResponse {
        $this->ensureSupplementAndWeekSharePhase($supplement, $protocolTemplatePhaseWeek);

        $supplementWeek = SupplementWeek::query()->firstOrCreate([
            'supplement_id' => $supplement->id,
            'protocol_template_phase_week_id' => $protocolTemplatePhaseWeek->id,
        ]);

        if ($supplementWeek->wasRecentlyCreated) {
            AuditLogger::created($supplementWeek);
        }

        return back();
    }

    public function destroySupplementWeek(
        Supplement $supplement,
        ProtocolTemplatePhaseWeek $protocolTemplatePhaseWeek,
    ): RedirectResponse {
        $this->ensureSupplementAndWeekSharePhase($supplement, $protocolTemplatePhaseWeek);

        $supplementWeek = SupplementWeek::query()
            ->where('supplement_id', $supplement->id)
            ->where('protocol_template_phase_week_id', $protocolTemplatePhaseWeek->id)
            ->first();

        if ($supplementWeek) {
            AuditLogger::deleted($supplementWeek);
            $supplementWeek->delete();
        }

        return back();
    }

    /** @return array{name: string} */
    private function validateTemplate(Request $request, ?ProtocolTemplate $protocolTemplate = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('protocol_templates', 'name')->ignore($protocolTemplate),
            ],
        ]);
    }

    /** @return array{protocol_template_id: string, name: string, description: ?string, required: bool, start_after_previous_phase_weeks: ?int} */
    private function validatePhase(Request $request): array
    {
        return $request->validate([
            'protocol_template_id' => ['required', 'uuid', 'exists:protocol_templates,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'required' => ['required', 'boolean'],
            'start_after_previous_phase_weeks' => ['nullable', 'integer', 'min:1', 'max:104'],
        ]);
    }

    /**
     * @return array{
     *     protocol_template_phase_id: string,
     *     name: string,
     *     description?: ?string,
     *     instructions?: ?string,
     *     supplement_type: string,
     *     dosis_type?: ?string,
     *     dosis?: ?float,
     *     unit?: ?string,
     *     add_by_default?: bool,
     *     max_aantal_in_fase?: ?int,
     *     min_aantal_per_week?: int,
     *     rust_periode_in_weken?: int
     * }
     */
    private function validateSupplement(Request $request, bool $creating = false): array
    {
        $data = $request->validate([
            'protocol_template_phase_id' => ['required', 'uuid', 'exists:protocol_template_phases,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'supplement_type' => ['required', Rule::enum(SupplementType::class)],
            'dosis_type' => ['nullable', 'required_with:dosis,unit', Rule::enum(SupplementDoseType::class)],
            'dosis' => ['nullable', 'required_with:dosis_type,unit', 'numeric', 'gt:0'],
            'unit' => ['nullable', 'required_with:dosis_type,dosis', Rule::enum(SupplementDoseUnit::class)],
            'add_by_default' => ['sometimes', 'boolean'],
            'max_aantal_in_fase' => ['nullable', 'integer', 'min:1'],
            'min_aantal_per_week' => ['sometimes', 'integer', 'min:0'],
            'rust_periode_in_weken' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($creating) {
            $data += [
                'add_by_default' => false,
                'min_aantal_per_week' => 4,
                'rust_periode_in_weken' => 2,
            ];
        }

        return $data;
    }

    private function renumberPhases(string $protocolTemplateId): void
    {
        ProtocolTemplatePhase::query()
            ->where('protocol_template_id', $protocolTemplateId)
            ->orderBy('order')
            ->get()
            ->each(fn (ProtocolTemplatePhase $phase, int $index) => $phase->update(['order' => $index + 1]));
    }

    private function ensureSupplementAndWeekSharePhase(
        Supplement $supplement,
        ProtocolTemplatePhaseWeek $protocolTemplatePhaseWeek,
    ): void {
        abort_unless(
            $supplement->protocol_template_phase_id === $protocolTemplatePhaseWeek->protocol_template_phase_id,
            422,
            'The supplement and week must belong to the same phase.',
        );
    }
}
