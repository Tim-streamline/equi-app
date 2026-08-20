<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SupplementType;
use App\Http\Controllers\Controller;
use App\Models\ProtocolType;
use App\Models\ProtocolTypePhase;
use App\Models\ProtocolTypePhaseWeek;
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
            'protocolTypes' => ProtocolType::query()
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

    public function storeType(Request $request): RedirectResponse
    {
        $data = $this->validateType($request);
        $protocolType = ProtocolType::create($data);
        AuditLogger::created($protocolType);

        return back()->with('success', 'Protocol type created.');
    }

    public function updateType(Request $request, ProtocolType $protocolType): RedirectResponse
    {
        $data = $this->validateType($request, $protocolType);
        $before = $protocolType->only(array_keys($data));
        $protocolType->update($data);
        AuditLogger::updated($protocolType, $before);

        return back()->with('success', 'Protocol type updated.');
    }

    public function destroyType(ProtocolType $protocolType): RedirectResponse
    {
        if ($protocolType->protocols()->exists()) {
            return back()->with('error', 'Dit protocoltype is gekoppeld aan een of meer protocollen en kan niet worden verwijderd.');
        }

        AuditLogger::deleted($protocolType);
        $protocolType->delete();

        return back()->with('success', 'Protocol type removed.');
    }

    public function storePhase(Request $request): RedirectResponse
    {
        $data = $this->validatePhase($request);

        $phase = DB::transaction(function () use ($data) {
            $protocolType = ProtocolType::query()->lockForUpdate()->findOrFail($data['protocol_type_id']);
            $data['order'] = ((int) $protocolType->phases()->max('order')) + 1;

            return ProtocolTypePhase::create($data);
        });

        AuditLogger::created($phase);

        return back()->with('success', 'Phase created.');
    }

    public function updatePhase(Request $request, ProtocolTypePhase $protocolTypePhase): RedirectResponse
    {
        $data = $this->validatePhase($request);
        $oldTypeId = $protocolTypePhase->protocol_type_id;

        if ($data['protocol_type_id'] !== $oldTypeId && $protocolTypePhase->protocolPhases()->exists()) {
            return back()->with('error', 'Een gebruikte fase kan niet naar een ander protocoltype worden verplaatst.');
        }

        DB::transaction(function () use ($data, $oldTypeId, $protocolTypePhase) {
            $before = $protocolTypePhase->only(['protocol_type_id', 'name', 'description', 'required', 'order']);

            if ($data['protocol_type_id'] !== $oldTypeId) {
                ProtocolType::query()->lockForUpdate()->whereKey([$oldTypeId, $data['protocol_type_id']])->get();
                $data['order'] = ((int) ProtocolTypePhase::query()
                    ->where('protocol_type_id', $data['protocol_type_id'])
                    ->max('order')) + 1;
            }

            $protocolTypePhase->update($data);
            AuditLogger::updated($protocolTypePhase, $before);

            if ($data['protocol_type_id'] !== $oldTypeId) {
                $this->renumberPhases($oldTypeId);
            }
        });

        return back()->with('success', 'Phase updated.');
    }

    public function destroyPhase(ProtocolTypePhase $protocolTypePhase): RedirectResponse
    {
        if ($protocolTypePhase->protocolPhases()->exists()) {
            return back()->with('error', 'Deze fase is gekoppeld aan een of meer protocollen en kan niet worden verwijderd.');
        }

        DB::transaction(function () use ($protocolTypePhase) {
            $protocolTypeId = $protocolTypePhase->protocol_type_id;
            AuditLogger::deleted($protocolTypePhase);
            $protocolTypePhase->delete();
            $this->renumberPhases($protocolTypeId);
        });

        return back()->with('success', 'Phase removed.');
    }

    public function storeWeek(ProtocolTypePhase $protocolTypePhase): RedirectResponse
    {
        $week = DB::transaction(function () use ($protocolTypePhase) {
            $phase = ProtocolTypePhase::query()->lockForUpdate()->findOrFail($protocolTypePhase->id);

            return $phase->weeks()->create([
                'number' => ((int) $phase->weeks()->max('number')) + 1,
            ]);
        });

        AuditLogger::created($week);

        return back()->with('success', 'Week added.');
    }

    public function destroyWeek(ProtocolTypePhaseWeek $protocolTypePhaseWeek): RedirectResponse
    {
        DB::transaction(function () use ($protocolTypePhaseWeek) {
            $phaseId = $protocolTypePhaseWeek->protocol_type_phase_id;
            ProtocolTypePhase::query()->lockForUpdate()->findOrFail($phaseId);
            AuditLogger::deleted($protocolTypePhaseWeek);
            $protocolTypePhaseWeek->delete();

            ProtocolTypePhaseWeek::query()
                ->where('protocol_type_phase_id', $phaseId)
                ->orderBy('number')
                ->get()
                ->each(fn (ProtocolTypePhaseWeek $week, int $index) => $week->update(['number' => $index + 1]));
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

        if (($data['protocol_type_phase_id'] ?? $supplement->protocol_type_phase_id) !== $supplement->protocol_type_phase_id
            && $supplement->protocolPhaseSupplements()->exists()) {
            return back()->with('error', 'Een gebruikt supplement kan niet naar een andere fase worden verplaatst.');
        }

        $before = $supplement->only(array_keys($data));
        $supplement->update($data);

        if ($supplement->wasChanged('protocol_type_phase_id')) {
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
        ProtocolTypePhaseWeek $protocolTypePhaseWeek,
    ): RedirectResponse {
        $this->ensureSupplementAndWeekSharePhase($supplement, $protocolTypePhaseWeek);

        $supplementWeek = SupplementWeek::query()->firstOrCreate([
            'supplement_id' => $supplement->id,
            'protocol_type_phase_week_id' => $protocolTypePhaseWeek->id,
        ]);

        if ($supplementWeek->wasRecentlyCreated) {
            AuditLogger::created($supplementWeek);
        }

        return back();
    }

    public function destroySupplementWeek(
        Supplement $supplement,
        ProtocolTypePhaseWeek $protocolTypePhaseWeek,
    ): RedirectResponse {
        $this->ensureSupplementAndWeekSharePhase($supplement, $protocolTypePhaseWeek);

        $supplementWeek = SupplementWeek::query()
            ->where('supplement_id', $supplement->id)
            ->where('protocol_type_phase_week_id', $protocolTypePhaseWeek->id)
            ->first();

        if ($supplementWeek) {
            AuditLogger::deleted($supplementWeek);
            $supplementWeek->delete();
        }

        return back();
    }

    /** @return array{name: string} */
    private function validateType(Request $request, ?ProtocolType $protocolType = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('protocol_types', 'name')->ignore($protocolType),
            ],
        ]);
    }

    /** @return array{protocol_type_id: string, name: string, description: ?string, required: bool} */
    private function validatePhase(Request $request): array
    {
        return $request->validate([
            'protocol_type_id' => ['required', 'uuid', 'exists:protocol_types,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'required' => ['required', 'boolean'],
        ]);
    }

    /**
     * @return array{
     *     protocol_type_phase_id: string,
     *     name: string,
     *     description?: ?string,
     *     supplement_type: string,
     *     add_by_default?: bool,
     *     max_aantal_in_fase?: ?int,
     *     min_aantal_per_week?: int,
     *     rust_periode_in_weken?: int
     * }
     */
    private function validateSupplement(Request $request, bool $creating = false): array
    {
        $data = $request->validate([
            'protocol_type_phase_id' => ['required', 'uuid', 'exists:protocol_type_phases,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'supplement_type' => ['required', Rule::enum(SupplementType::class)],
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

    private function renumberPhases(string $protocolTypeId): void
    {
        ProtocolTypePhase::query()
            ->where('protocol_type_id', $protocolTypeId)
            ->orderBy('order')
            ->get()
            ->each(fn (ProtocolTypePhase $phase, int $index) => $phase->update(['order' => $index + 1]));
    }

    private function ensureSupplementAndWeekSharePhase(
        Supplement $supplement,
        ProtocolTypePhaseWeek $protocolTypePhaseWeek,
    ): void {
        abort_unless(
            $supplement->protocol_type_phase_id === $protocolTypePhaseWeek->protocol_type_phase_id,
            422,
            'The supplement and week must belong to the same phase.',
        );
    }
}
