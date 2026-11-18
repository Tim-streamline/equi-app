<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CopyProtocolRequest;
use App\Http\Requests\Admin\SaveProtocolRequest;
use App\Models\BewegingAdvies;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\LibraryItem;
use App\Models\ManagementAdvies;
use App\Models\Protocol;
use App\Models\ProtocolAdvice;
use App\Models\ProtocolManualAdvies;
use App\Models\ProtocolAnalysis;
use App\Models\ProtocolPhase;
use App\Models\ProtocolPhaseSupplement;
use App\Models\ProtocolTemplate;
use App\Models\ProtocolTemplatePhase;
use App\Models\Supplement;
use App\Models\Therapist;
use App\Models\User;
use App\Models\VoedingAdvies;
use App\Support\AuditLogger;
use App\Support\ProtocolCopier;
use App\Support\ProtocolDayHistory;
use App\Support\ProtocolDosage;
use App\Support\ProtocolNutrition;
use App\Support\ProtocolPreview;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProtocolController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Protocol::query()
            ->when($request->string('status')->toString(), fn ($query, $s) => $query->where('status', $s), fn ($query) => $query->where('status', '!=', 'archived'))
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
            ->latest();
        $selectionIds = (clone $query)->pluck('id');
        $protocols = $query->paginate(20)->withQueryString();

        return Inertia::render('Protocols/Index', [
            'protocols' => $protocols,
            'selectionIds' => $selectionIds,
            'filters' => $request->only('status', 'q'),
        ]);
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // "delete" removes the protocols with their intake; "delete_protocol" keeps the intake.
            'action' => ['required', 'in:archive,restore,delete,delete_protocol'],
            'ids' => ['required', 'array', 'min:1', 'max:10000'],
            'ids.*' => ['required', 'uuid', 'distinct', 'exists:protocols,id'],
            'confirmed' => ['required_if:action,delete,delete_protocol', 'accepted_if:action,delete,delete_protocol'],
        ]);
        DB::transaction(function () use ($data) {
            \App\Support\LibrarySyncLock::acquire();
            $protocols = Protocol::whereIn('id', $data['ids'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($protocols->count() === count($data['ids']), 409, 'De selectie is gewijzigd. Vernieuw het overzicht.');
            if ($data['action'] === 'delete') {
                // An intake goes only when every protocol that uses it goes with it.
                $left = Protocol::whereIn('intake_booking_id', $protocols->pluck('intake_booking_id'))->whereNotIn('id', $protocols->modelKeys())->value('intake_booking_id');
                if ($left) {
                    throw ValidationException::withMessages(['protocol' => Protocol::intakeInUseMessage($protocols->firstWhere('intake_booking_id', $left)->title)]);
                }
                // Copies first: the protocol whose intake they use takes that intake with it.
                $own = IntakeBooking::whereIn('protocol_id', $protocols->modelKeys())->pluck('protocol_id', 'id');
                $protocols = $protocols->sortBy(fn (Protocol $protocol) => $own->get($protocol->intake_booking_id) === $protocol->id ? 1 : 0)->values();
            }
            foreach ($protocols as $protocol) {
                if ($data['action'] === 'delete') {
                    AuditLogger::deleted($protocol, 'Bulk verwijderen');
                    $protocol->delete();

                    continue;
                }
                if ($data['action'] === 'delete_protocol') {
                    AuditLogger::deleted($protocol, 'Bulk verwijderen, intake bewaard');
                    $heir = $protocol->deleteKeepingIntake();
                    if ($heir?->wasRecentlyCreated) {
                        AuditLogger::created($heir, 'Leeg concept voor de bewaarde intake van protocol '.$protocol->id);
                    }

                    continue;
                }
                $before = $protocol->only(['status', 'archived_previous_status']);
                if ($data['action'] === 'archive' && $protocol->status !== 'archived') {
                    $protocol->archived_previous_status = $protocol->status;
                    $protocol->status = 'archived';
                } elseif ($data['action'] === 'restore' && $protocol->status === 'archived') {
                    if ($protocol->archived_previous_status === 'active') $protocol->assertIntakeSubmitted();
                    $protocol->status = $protocol->archived_previous_status ?? 'paused';
                    $protocol->archived_previous_status = null;
                } else {
                    continue;
                }
                $protocol->save();
                AuditLogger::updated($protocol, $before, 'Bulk '.$data['action']);
            }
        });

        return back()->with('success', count($data['ids']).' protocollen verwerkt.');
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $draft = app(ProtocolNutrition::class)->draftForHorse($request->string('horse_id')->toString());
        if ($draft) {
            return to_route('admin.protocols.edit', $draft);
        }

        return Inertia::render('Protocols/Edit', [
            'protocol' => null,
            ...$this->editorOptions($request->string('horse_id')->toString()),
        ]);
    }

    public function preview(SaveProtocolRequest $request, ?Protocol $protocol = null): JsonResponse
    {
        $options = $request->validate(['preview_week' => ['sometimes', 'integer', 'between:1,10400']]);

        return response()->json(app(ProtocolPreview::class)->build($request->validated(), $protocol, $options['preview_week'] ?? 1));
    }

    public function store(SaveProtocolRequest $request): RedirectResponse
    {
        $protocolTemplate = ProtocolTemplate::query()->findOrFail($request->validated('protocol_template_id'));
        $data = $this->withRequiredPhases($request->validated());

        try {
            $protocol = DB::transaction(function () use ($data, $protocolTemplate) {
                \App\Support\LibrarySyncLock::acquire();
                $this->assertOverridesAvailable($data);
                Horse::whereKey($data['horse_id'])->lockForUpdate()->firstOrFail();
                $drafts = Protocol::where('horse_id', $data['horse_id'])->where('status', 'draft')->whereNull('protocol_template_id')->lockForUpdate()->get();
                if ($drafts->count() > 1) {
                    throw ValidationException::withMessages(['horse_id' => 'Open het protocol vanuit de juiste intake. Er zijn meerdere concepten.']);
                }
                $protocol = $drafts->first() ?? new Protocol;
                $protocol->assertIntakeSubmitted();
                $protocol->fill($this->protocolAttributes($data, $protocolTemplate) + $this->startDate($protocol, $data))->save();
                $this->syncStructure($protocol, $data);
                AuditLogger::created($protocol);

                return $protocol;
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['save' => 'Het protocol kon niet worden opgeslagen. Er zijn geen wijzigingen bewaard. Probeer opnieuw.']);
        }

        return to_route('admin.protocols.edit', $protocol)
            ->with('success', 'Protocol aangemaakt.');
    }

    public function edit(Protocol $protocol): Response
    {
        $this->loadEditorRelations($protocol);

        return Inertia::render('Protocols/Edit', [
            'protocol' => $protocol,
            'weeklyUpdates' => DB::table('protocol_weekly_updates')->where('protocol_id', $protocol->id)->orderByDesc('week_number')->get(),
            'evaluation' => app(\App\Support\ProtocolEvaluation::class)->admin($protocol),
            ...$this->editorOptions($protocol->horse_id, $protocol->therapist_id, $protocol),
        ]);
    }

    public function update(SaveProtocolRequest $request, Protocol $protocol): RedirectResponse
    {
        $data = $protocol->protocol_template_id ? $request->validated() : $this->withRequiredPhases($request->validated(), $protocol);
        if ($protocol->status === 'archived') {
            $data['status'] = 'archived';
        }

        try {
            DB::transaction(function () use ($data, $protocol) {
                \App\Support\LibrarySyncLock::acquire();
                $this->assertOverridesAvailable($data);
                $protocol = Protocol::query()->lockForUpdate()->findOrFail($protocol->id);
                if ($protocol->status === 'archived') {
                    $data['status'] = 'archived';
                }
                $protocol->assertIntakeSubmitted();
                $history = app(ProtocolDayHistory::class);
                $history->preserve($protocol, CarbonImmutable::now($history->timezone($protocol)));
                $attributes = $this->protocolAttributes($data, $protocol->protocol_template_id ? null : ProtocolTemplate::findOrFail($data['protocol_template_id']))
                    + $this->startDate($protocol, $data);
                if ($data['published'] && $protocol->published_at) {
                    $attributes['published_at'] = $protocol->published_at;
                }
                $before = $protocol->only(array_keys($attributes));
                $protocol->update($attributes);
                $this->syncStructure($protocol, $data);
                AuditLogger::updated($protocol, $before);
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['save' => 'Het protocol kon niet worden opgeslagen. Er zijn geen wijzigingen bewaard. Probeer opnieuw.']);
        }

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
            'manualAdviezen.phases:id,title,order',
        ]);

        return Inertia::render('Protocols/Show', ['protocol' => $protocol, 'evaluation' => app(\App\Support\ProtocolEvaluation::class)->admin($protocol)]);
    }

    public function updateStatus(Request $request, Protocol $protocol): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', 'in:draft,active,paused,completed']])['status'];
        DB::transaction(function () use ($protocol, $status, $request) {
            \App\Support\LibrarySyncLock::acquire();
            $protocol = Protocol::whereKey($protocol->id)->lockForUpdate()->firstOrFail();
            abort_if($protocol->status === 'archived', 422, 'Herstel het protocol eerst vanuit het archief.');
            if ($status === 'active') $protocol->assertIntakeSubmitted();
            $before = $protocol->only('status');
            $protocol->update(['status' => $status]);
            app(\App\Support\ProtocolTiming::class)->synchronize($protocol);
            AuditLogger::updated($protocol, $before, $request->input('reason'));
        });

        return back()->with('success', "Protocol gemarkeerd als {$status}.");
    }

    /**
     * Copy dialog: the horses this protocol may be copied to. "same" is the protocol's own horse, "test" the
     * active horses of the team's test users. Each comes with the drafts a copy can fill.
     */
    public function copyTargets(Request $request, Protocol $protocol): JsonResponse
    {
        $to = $request->validate(['to' => ['required', 'in:same,test']])['to'];
        // The intake a copy will use too, read when the dialog opens and not from a page that may have been open for a
        // while. Its user stays the previous owner after a horse transfer.
        $intake = $protocol->intake()->with('user:id,name')->first(['id', 'user_id', 'submitted_at', 'status']);
        $horses = Horse::query()
            ->when($to === 'same', fn ($query) => $query->whereKey($protocol->horse_id))
            ->when($to === 'test', fn ($query) => $query->where('status', 'active')->whereHas('owner', fn ($owner) => $owner->where('is_test_user', true)))
            ->with('owner:id,name,email,is_test_user')
            ->get(['id', 'owner_id', 'name', 'breed', 'status'])
            ->sortBy(fn (Horse $horse) => [mb_strtolower($horse->owner->name), $horse->owner_id, mb_strtolower($horse->name), $horse->id])
            ->values();
        $horseIds = $horses->pluck('id');
        $existing = Protocol::query()->whereIn('horse_id', $horseIds)->oldest()->orderBy('id')->get(['id', 'horse_id', 'title', 'status'])->groupBy('horse_id');
        $drafts = $horses->pluck('owner')->unique('id')->flatMap(fn (User $owner) => ProtocolCopier::reusableDrafts($owner)
            ->whereIn('horse_id', $horses->where('owner_id', $owner->id)->pluck('id'))
            ->with('intake:id,submitted_at')
            ->oldest()
            ->orderBy('id')
            ->get(['id', 'horse_id', 'intake_booking_id', 'title', 'created_at']))->groupBy('horse_id');

        return response()->json(['intake' => ['submitted' => (bool) $intake?->submitted_at, 'cancelled' => $intake?->status === 'cancelled', 'user' => $intake?->user?->only(['id', 'name'])], 'horses' => $horses->map(fn (Horse $horse): array => [
            'id' => $horse->id,
            'name' => $horse->name,
            'breed' => $horse->breed,
            'status' => $horse->status,
            'owner' => $horse->owner->only(['id', 'name', 'email', 'is_test_user']),
            'has_active_protocol' => ($existing->get($horse->id) ?? collect())->contains('status', 'active'),
            // Lets the dialog warn when the copy's title would match one the customer already sees for this horse.
            'protocols' => ($existing->get($horse->id) ?? collect())->map(fn (Protocol $protocol): array => $protocol->only(['id', 'title']))->values(),
            'drafts' => ($drafts->get($horse->id) ?? collect())->map(fn (Protocol $draft): array => [
                'id' => $draft->id,
                'title' => $draft->title,
                'created_at' => $draft->created_at,
                'intake_submitted' => (bool) $draft->intake?->submitted_at,
            ])->values(),
        ])]);
    }

    public function copy(CopyProtocolRequest $request, Protocol $protocol, ProtocolCopier $copier): RedirectResponse
    {
        $data = $request->validated();
        $owner = User::query()->findOrFail($data['user_id']);
        $horse = Horse::query()->findOrFail($data['horse_id']);

        try {
            $result = $copier->copy($protocol, $owner, $horse, $data['destination'] === 'draft' ? $data['draft_id'] : null, (bool) ($data['include_analysis'] ?? false), $data['title'] ?? null);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['copy' => 'Het protocol kon niet worden gekopieerd. Er is niets opgeslagen. Probeer opnieuw.']);
        }

        $message = "Protocol gekopieerd naar {$horse->name} ({$owner->name}) als ongepubliceerd concept.";
        $message .= $result['reused_draft'] ? ' Het concept houdt zijn eigen intake.' : ' De kopie gebruikt dezelfde intake als het origineel.';
        $message .= $result['intake_submitted'] ? ' Je kunt de kopie direct aanpassen.' : ' Aanpassen kan zodra die intake is verstuurd.';
        if ($result['missing_dosages'] > 0) {
            $message .= " Let op: {$result['missing_dosages']} automatische dosering(en) konden niet worden berekend omdat het gewicht van het paard onbekend is. Controleer de doseringen.";
        }

        return to_route('admin.protocols.show', $result['protocol'])->with('success', $message);
    }

    /** @return array<string, mixed> */
    private function editorOptions(?string $selectedHorseId = null, ?string $currentTherapistId = null, ?Protocol $protocol = null): array
    {
        $nutrition = app(ProtocolNutrition::class);
        $horses = Horse::query()->where('status', 'active')->with('owner:id,name,email')->orderBy('name')->get(['id', 'owner_id', 'name', 'breed', 'age', 'sex', 'weight_kg', 'status']);
        $answers = $horses->mapWithKeys(fn ($horse) => [$horse->id => $nutrition->answers($protocol && $protocol->horse_id === $horse->id ? $protocol : $nutrition->draftForHorse($horse->id))]);

        return [
            'intakeWeights' => $horses->mapWithKeys(fn ($horse) => [$horse->id => $nutrition->weight($answers[$horse->id]['gewicht'] ?? null) ?? $horse->weight_kg]),
            'selectedHorseId' => $selectedHorseId ?: null,
            'libraryItems' => LibraryItem::query()->whereNotNull('published_at')->orderBy('title')->get(['id', 'title']),
            'intakeFeeds' => $horses->mapWithKeys(fn ($horse) => [$horse->id => $nutrition->forProtocol(new Protocol, $answers[$horse->id])['feeds']]),
            'horses' => $horses,
            'therapists' => Therapist::availableFor($currentTherapistId)
                ->orderBy('name')
                ->get(['id', 'name', 'title', 'archived_at']),
            // Archived advice stays listed only for a protocol that already uses it.
            'voedingAdviezen' => VoedingAdvies::availableFor($protocol?->voedingAdviezen->pluck('voeding_advies_id')->all() ?? [])->ordered()->get(),
            'managementAdviezen' => ManagementAdvies::availableFor($protocol?->managementAdviezen->pluck('management_advies_id')->all() ?? [])->ordered()->get(),
            'bewegingAdviezen' => BewegingAdvies::availableFor($protocol?->bewegingAdviezen->pluck('beweging_advies_id')->all() ?? [])->ordered()->get(),
            'doseBuildups' => \App\Models\DoseBuildup::orderBy('name')->get(),
            'protocolTemplates' => ProtocolTemplate::query()
                ->with([
                    'phases:id,protocol_template_id,order,name,description,required,start_after_previous_phase_weeks',
                    'phases.weeks:id,protocol_template_phase_id,number',
                    'phases.supplements:id,dose_buildup_id,protocol_template_phase_id,name,description,instructions,supplement_type,dosis_type,dosis,unit,add_by_default,max_aantal_in_fase,min_aantal_per_week,rust_periode_in_weken',
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
            'intake',
            'horse:id,name,owner_id,breed,age,sex,weight_kg,status',
            'horse.owner:id,name,email',
            'therapist:id,name,title',
            'phases.weeks',
            'phases.supplements.weeks.protocolPhaseWeek',
            'analysis.advice',
            'voedingAdviezen',
            'managementAdviezen',
            'bewegingAdviezen',
            'manualAdviezen.phases:id',
        ]);
    }

    /**
     * An explicit start date chosen by the therapist, set only while the plan has none.
     * Call with the protocol row locked: a date from a form opened before the owner (or
     * another admin) started the plan must not move day one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function startDate(Protocol $protocol, array $data): array
    {
        $date = $data['start_on'] ?? null;
        if (! $date) {
            return [];
        }
        if ($protocol->started_at) {
            $current = $protocol->started_at->toDateString();
            if ($current !== $date) {
                throw ValidationException::withMessages(['start_on' => 'Dit protocol is al gestart op '.$current.'. De startdatum kan niet meer worden gewijzigd.']);
            }

            return [];
        }

        return ['started_at' => $date];
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

    /**
     * Manual advice is replaced by the submitted list: rows keep their id, new rows are created and rows
     * left out are removed. An id this protocol no longer holds (removed in another tab) is rejected,
     * never recreated. Runs inside the save transaction, after the protocol row is locked; syncStructure()
     * audits the change.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $phaseIdsByKey  Saved phase id per submitted phase client_key.
     */
    private function syncManualAdvice(Protocol $protocol, array $rows, array $phaseIdsByKey): void
    {
        $existing = $protocol->manualAdviezen()->lockForUpdate()->get()
            ->keyBy(fn (ProtocolManualAdvies $advice) => strtolower($advice->id));
        $kept = [];
        $positions = [];

        foreach (array_values($rows) as $index => $row) {
            $id = isset($row['id']) ? strtolower($row['id']) : null;
            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages(["manual_advice.{$index}.id" => 'Dit handmatige advies bestaat niet meer. Vernieuw de pagina en voeg het opnieuw toe.']);
            }
            $phaseIds = [];
            if ($row['applies_to'] === 'phases') {
                foreach ($row['phase_keys'] as $key) {
                    $phaseIds[] = $phaseIdsByKey[$key] ?? throw ValidationException::withMessages(["manual_advice.{$index}.phase_keys" => 'Een gekozen fase bestaat niet meer in dit protocol. Kies de fases opnieuw.']);
                }
            }

            $category = $row['category'];
            $advice = $id !== null ? $existing->get($id) : new ProtocolManualAdvies(['protocol_id' => $protocol->id]);
            $advice->fill([
                'category' => $category,
                'title' => $row['title'],
                'description' => $row['description'],
                'management_category' => $category === 'management' ? $row['management_category'] : null,
                'action' => $category === 'voeding' ? 'do' : ($row['action'] ?? 'do'),
                'applies_to' => $row['applies_to'],
                'sort_order' => $positions[$category] = ($positions[$category] ?? 0) + 1,
            ])->save();
            $advice->phases()->sync($phaseIds);
            $kept[] = $advice->id;
        }

        $protocol->manualAdviezen()->whereNotIn('id', $kept)->delete();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProtocolManualAdvies>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function manualAdviceState($rows): array
    {
        return $rows->sortBy(fn (ProtocolManualAdvies $advice) => $advice->category.'|'.str_pad((string) $advice->sort_order, 6, '0', STR_PAD_LEFT))
            ->map(fn (ProtocolManualAdvies $advice): array => [
                ...$advice->only(['id', 'category', 'title', 'description', 'management_category', 'action', 'applies_to']),
                'phase_ids' => $advice->phases->pluck('id')->sort()->values()->all(),
            ])->values()->all();
    }

    /**
     * Overrides were validated before the save transaction; a measure deleted or moved since then would leave
     * an override pointing nowhere. The measure library takes the same lock, so this check is final.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertOverridesAvailable(array $data): void
    {
        $missing = \App\Support\ProtocolMeasures::missingOverrideKeys($data['customer_settings'] ?? null);
        if ($missing !== []) {
            throw ValidationException::withMessages(array_fill_keys($missing, SaveProtocolRequest::ADVICE_UNAVAILABLE));
        }
    }

    /** @param array<string, mixed> $data */
    private function syncStructure(Protocol $protocol, array $data): void
    {
        $phaseIds = [];
        $phaseIdsByKey = [];
        // Before phases are removed: their links to manual advice go with them.
        $manualBefore = $this->manualAdviceState($protocol->manualAdviezen()->with('phases')->get());
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
            $phaseIdsByKey[$phaseData['client_key']] = $phase->id;
            $this->syncPhaseWeeks($phase, (int) $phaseData['week_count']);
            $this->syncPhaseSupplements($phase, $phaseData['supplements']);
        }

        $protocol->phases()->whereNotIn('id', $phaseIds)->delete();
        $this->syncAnalysis($protocol, $data['analysis'] ?? [], $data['advice']);
        $this->syncAdviceSelections($protocol, $data['voeding_advies_ids'], 'voedingAdviezen', VoedingAdvies::class, 'voeding_advies_id');
        $this->syncAdviceSelections($protocol, $data['management_advies_ids'], 'managementAdviezen', ManagementAdvies::class, 'management_advies_id');
        $this->syncAdviceSelections($protocol, $data['beweging_advies_ids'], 'bewegingAdviezen', BewegingAdvies::class, 'beweging_advies_id');
        if (array_key_exists('manual_advice', $data)) {
            $this->syncManualAdvice($protocol, $data['manual_advice'], $phaseIdsByKey);
        }
        $manualAfter = $this->manualAdviceState($protocol->manualAdviezen()->with('phases')->get());
        if ($manualBefore !== $manualAfter) {
            AuditLogger::log('updated', $protocol, ['manual_advice' => $manualBefore], ['manual_advice' => $manualAfter], 'Handmatige adviezen');
        }
        app(\App\Support\ProtocolTiming::class)->synchronize($protocol);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withRequiredPhases(array $data, ?Protocol $protocol = null): array
    {
        $horse = Horse::findOrFail($data['horse_id']);
        $nutrition = app(ProtocolNutrition::class);
        $horseWeightKg = $nutrition->actualWeight((new Protocol(['customer_settings' => $data['customer_settings'] ?? []]))->setRelation('horse', $horse), $nutrition->answers($protocol ?? $nutrition->draftForHorse($horse->id)));
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
                    'dosage_mode' => 'automatic',
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
        $protocol = $phase->protocol;
        $nutrition = app(ProtocolNutrition::class);
        $weight = $nutrition->actualWeight($protocol, $nutrition->answers($protocol));

        foreach ($supplements as $supplementIndex => $supplementData) {
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
                'dosage_mode' => $supplementData['dosage_mode'] ?? 'manual',
                'aantal_per_week' => $supplementData['aantal_per_week'] ?? null,
                'instructions' => $this->nullableValue($supplementData['instructions'] ?? null),
            ];
            if ($catalogSupplement) {
                $attributes += [
                    'supplement_id' => $catalogSupplement->id,
                    'administrations_per_day' => $catalogSupplement->administrations_per_day,
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
            $phaseIndex = $phase->order;
            $attributes = array_replace($attributes, app(\App\Support\ProtocolDoseBuildup::class)->attributes(
                $catalogSupplement ?? $selection, $supplementData, $weight, "phases.{$phaseIndex}.supplements.{$supplementIndex}.dosage",
            ));
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
        $expectation = array_key_exists('expectation_follow_up', $content) ? $content['expectation_follow_up'] : $analysis?->expectation_follow_up;

        if ($cause === '' && $adviceRows === [] && blank($summary) && $focus === [] && $observations === [] && blank($expectation)) {
            $analysis?->delete();

            return;
        }

        $analysis ??= new ProtocolAnalysis(['protocol_id' => $protocol->id]);
        $analysis->fill(['protocol_id' => $protocol->id, 'cause' => $cause, 'summary' => $summary,
            'focus_points' => $focus, 'observations' => $observations, 'expectation_follow_up' => $expectation])->save();
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

        foreach ($sourceIds as $index => $sourceId) {
            $snapshot = $snapshots->get($sourceId);

            if (! $snapshot) {
                // Validation ran before this transaction: an archive committed since then must not be snapshotted.
                $source = $sourceModel::query()->whereNull('archived_at')->sharedLock()->find($sourceId);
                if (! $source) {
                    throw ValidationException::withMessages(["{$sourceForeignKey}s.{$index}" => SaveProtocolRequest::ADVICE_UNAVAILABLE]);
                }
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

    private function nullableValue(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    private function templateDosage(Supplement $supplement, int|float|null $horseWeightKg): ?string
    {
        return app(ProtocolDosage::class)->calculate($supplement, $horseWeightKg);
    }
}
