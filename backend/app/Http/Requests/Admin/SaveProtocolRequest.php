<?php

namespace App\Http\Requests\Admin;

use App\Models\ProtocolPhaseSupplement;
use App\Models\ProtocolTemplatePhase;
use App\Models\Supplement;
use App\Rules\ShortProtocolText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveProtocolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|ShortProtocolText>>
     */
    public function rules(): array
    {
        return [
            'horse_id' => ['required', 'uuid', 'exists:horses,id'],
            'protocol_template_id' => ['required', 'uuid', 'exists:protocol_templates,id'],
            'therapist_id' => ['nullable', 'uuid', 'exists:therapists,id'],
            'title' => ['required', 'string', 'max:255'],
            'started_at' => ['nullable', 'date'],
            'status' => ['required', 'in:active,paused,completed'],
            'published' => ['required', 'boolean'],

            'customer_settings' => ['sometimes', 'array:target_weight_kg,sugar,protein,weekly_update_day,feed_overrides,management,hay_library_item_id,water_library_item_id'],
            'customer_settings.hay_library_item_id' => ['nullable', 'uuid', 'exists:library_items,id'],
            'customer_settings.water_library_item_id' => ['nullable', 'uuid', 'exists:library_items,id'],
            'customer_settings.target_weight_kg' => ['nullable', 'numeric', 'gt:0', 'max:2000'],
            'customer_settings.sugar' => ['nullable', 'string', 'max:80'],
            'customer_settings.protein' => ['nullable', 'string', 'max:80'],
            'customer_settings.weekly_update_day' => ['sometimes', 'integer', 'between:1,7'],
            'customer_settings.feed_overrides' => ['sometimes', 'array', 'max:100'],
            'customer_settings.feed_overrides.*' => ['array:id,status,note'],
            'customer_settings.feed_overrides.*.id' => ['required', 'string', 'size:64', 'distinct'],
            'customer_settings.feed_overrides.*.status' => ['required', 'in:continue,stop'],
            'customer_settings.feed_overrides.*.note' => ['nullable', 'string', 'max:5000'],
            'customer_settings.management' => ['sometimes', 'array', 'max:100'],
            'customer_settings.management.*' => ['array:id,category,action,instruction,note,frequency,url,cta_label'],
            'customer_settings.management.*.id' => ['required', 'uuid', 'distinct', 'exists:management_adviezen,id'],
            'customer_settings.management.*.category' => ['required', 'in:environment,care,monitoring'],
            'customer_settings.management.*.action' => ['required', 'in:do,avoid'],
            'customer_settings.management.*.instruction' => ['nullable', 'string', 'max:10000'],
            'customer_settings.management.*.note' => ['nullable', 'string', 'max:5000'],
            'customer_settings.management.*.frequency' => ['nullable', 'string', 'max:255'],
            'customer_settings.management.*.url' => ['nullable', 'url:http,https', 'max:2000'],
            'customer_settings.management.*.cta_label' => ['nullable', 'string', 'max:255'],

            'analysis' => ['nullable', 'array'],
            'analysis.cause' => ['nullable', 'string', 'max:10000'],
            'analysis.summary' => ['nullable', 'string', 'max:600', new ShortProtocolText(4)],
            'analysis.focus_points' => ['sometimes', 'array', 'max:4'],
            'analysis.focus_points.*' => ['array:title,body'],
            'analysis.focus_points.*.title' => ['required', 'string', 'max:48', 'not_regex:/^(voeding|management|training|beweging|verzorging|zorg|nutrition|movement)$/iu'],
            'analysis.focus_points.*.body' => ['required', 'string', 'max:160', new ShortProtocolText(1)],
            'analysis.observations' => ['sometimes', 'array', 'max:4'],
            'analysis.observations.*' => ['required', 'string', 'max:160', new ShortProtocolText(1)],
            'advice' => ['present', 'array', 'max:20'],
            'advice.*.id' => ['nullable', 'uuid'],
            'advice.*.icon_key' => ['required', 'in:leaf,run,horse'],
            'advice.*.title' => ['required', 'string', 'max:255'],
            'advice.*.body' => ['required', 'string', 'max:10000'],

            'voeding_advies_ids' => ['present', 'array', 'max:100'],
            'voeding_advies_ids.*' => ['required', 'uuid', 'distinct', 'exists:voeding_adviezen,id'],
            'management_advies_ids' => ['present', 'array', 'max:100'],
            'management_advies_ids.*' => ['required', 'uuid', 'distinct', 'exists:management_adviezen,id'],
            'beweging_advies_ids' => ['present', 'array', 'max:100'],
            'beweging_advies_ids.*' => ['required', 'uuid', 'distinct', 'exists:beweging_adviezen,id'],

            'phases' => ['present', 'array'],
            'phases.*.id' => ['nullable', 'uuid'],
            'phases.*.client_key' => ['required', 'string', 'max:100', 'distinct'],
            'phases.*.protocol_template_phase_id' => ['required', 'uuid', 'distinct', 'exists:protocol_template_phases,id'],
            'phases.*.week_count' => ['required', 'integer', 'min:0', 'max:104'],
            'phases.*.start_after_previous_phase_weeks' => ['nullable', 'integer', 'min:0', 'max:104'],
            'phases.*.supplements' => ['present', 'array', 'max:100'],
            'phases.*.supplements.*.id' => ['nullable', 'uuid'],
            'phases.*.supplements.*.supplement_id' => ['nullable', 'uuid', 'distinct:strict', 'exists:supplements,id'],
            'phases.*.supplements.*.dosage' => ['nullable', 'string', 'max:255'],
            'phases.*.supplements.*.aantal_per_week' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'phases.*.supplements.*.instructions' => ['nullable', 'string', 'max:10000'],
            'phases.*.supplements.*.week_numbers' => ['present', 'array', 'max:104'],
            'phases.*.supplements.*.week_numbers.*' => ['required', 'integer', 'min:1', 'max:104'],

        ];
    }

    public function messages(): array
    {
        return [
            'analysis.focus_points.*.title.not_regex' => 'Kies een inhoudelijk focuspunt, geen algemene adviescategorie.',
            'analysis.summary.max' => 'Houd de persoonlijke analyse kort: maximaal 600 tekens.',
            'analysis.focus_points.max' => 'Gebruik maximaal 4 focuspunten.',
            'analysis.focus_points.*.title.max' => 'Gebruik een korte titel van maximaal 48 tekens.',
            'analysis.focus_points.*.body.max' => 'Gebruik één korte doelzin van maximaal 160 tekens.',
            'analysis.observations.max' => 'Gebruik maximaal 4 observatiepunten.',
            'analysis.observations.*.max' => 'Gebruik één korte observatiezin van maximaal 160 tekens.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $hasCompactAnalysis = filled($this->input('analysis.summary'))
                || filled($this->input('analysis.focus_points'))
                || filled($this->input('analysis.observations'));
            if ($this->boolean('published') && $hasCompactAnalysis) {
                if (! filled($this->input('analysis.summary'))) {
                    $validator->errors()->add('analysis.summary', 'Vul een korte persoonlijke analyse in voordat je publiceert.');
                }
                $focus = $this->input('analysis.focus_points', []);
                if (! is_array($focus) || count($focus) < 3) {
                    $validator->errors()->add('analysis.focus_points', 'Vul 3 tot 4 inhoudelijke focuspunten in voordat je publiceert.');
                }
                if (! filled($this->input('analysis.observations'))) {
                    $validator->errors()->add('analysis.observations', 'Vul minimaal één observatiepunt in voordat je publiceert.');
                }
            }
            $protocolTemplateId = $this->input('protocol_template_id');
            $selectedPhaseIds = collect($this->input('phases', []))
                ->pluck('protocol_template_phase_id')
                ->filter()
                ->unique()
                ->values();

            if (! is_string($protocolTemplateId)) {
                return;
            }

            $protocol = $this->route('protocol');
            if ($protocol && $protocol->protocol_template_id !== $protocolTemplateId) {
                $validator->errors()->add('protocol_template_id', 'De protocoltemplate van een bestaand protocol kan niet worden gewijzigd.');

                return;
            }

            if ($selectedPhaseIds->isNotEmpty()) {
                $availablePhases = ProtocolTemplatePhase::query()
                    ->where('protocol_template_id', $protocolTemplateId)
                    ->whereIn('id', $selectedPhaseIds)
                    ->get(['id', 'required']);

                if ($availablePhases->count() !== $selectedPhaseIds->count()) {
                    $validator->errors()->add('phases', 'All protocol phases must belong to the selected protocol template.');

                    return;
                }
            }

            $submittedPhaseIds = collect($this->input('phases', []))->pluck('id')->filter();
            $storedPhases = $protocol
                ? $protocol->phases()->whereIn('id', $submittedPhaseIds)->get([
                    'id',
                    'protocol_template_phase_id',
                    'required',
                ])->keyBy('id')
                : collect();

            foreach ($this->input('phases', []) as $phaseIndex => $phase) {
                if (empty($phase['id'])) {
                    continue;
                }

                $storedPhase = $storedPhases->get($phase['id']);
                if (! $storedPhase || $storedPhase->protocol_template_phase_id !== ($phase['protocol_template_phase_id'] ?? null)) {
                    $validator->errors()->add(
                        "phases.{$phaseIndex}.protocol_template_phase_id",
                        'Een bestaande protocolfase kan niet naar een andere templatefase worden omgezet.',
                    );
                }
            }

            $submittedSelectionIds = collect($this->input('phases', []))
                ->flatMap(fn (array $phase): array => collect($phase['supplements'] ?? [])->pluck('id')->filter()->all());
            $storedSelections = $protocol
                ? ProtocolPhaseSupplement::query()
                    ->whereHas('phase', fn ($query) => $query->where('protocol_id', $protocol->id))
                    ->whereIn('id', $submittedSelectionIds)
                    ->get([
                        'id',
                        'protocol_phase_id',
                        'supplement_id',
                        'max_aantal_in_fase',
                        'min_aantal_per_week',
                    ])
                    ->keyBy('id')
                : collect();

            $selectedSupplements = Supplement::query()
                ->whereIn(
                    'id',
                    collect($this->input('phases', []))->flatMap(
                        fn (array $phase): array => collect($phase['supplements'] ?? [])->pluck('supplement_id')->all(),
                    )->unique(),
                )
                ->get([
                    'id',
                    'protocol_template_phase_id',
                    'max_aantal_in_fase',
                    'min_aantal_per_week',
                ])
                ->keyBy('id');

            foreach ($this->input('phases', []) as $phaseIndex => $phase) {
                foreach ($phase['supplements'] ?? [] as $supplementIndex => $supplement) {
                    $supplementId = $supplement['supplement_id'] ?? null;
                    $storedSelection = filled($supplement['id'] ?? null)
                        ? $storedSelections->get($supplement['id'])
                        : null;
                    if ($supplementId === null && empty($supplement['id'])) {
                        $validator->errors()->add(
                            "phases.{$phaseIndex}.supplements.{$supplementIndex}.supplement_id",
                            'Selecteer een bestaand supplement.',
                        );

                        continue;
                    }

                    if (! empty($supplement['id']) && ! $storedSelection) {
                        $validator->errors()->add(
                            "phases.{$phaseIndex}.supplements.{$supplementIndex}.id",
                            'Het geselecteerde protocolsupplement bestaat niet binnen dit protocol.',
                        );

                        continue;
                    }

                    if ($storedSelection
                        && ($storedSelection->protocol_phase_id !== ($phase['id'] ?? null)
                            || $storedSelection->supplement_id !== $supplementId)) {
                        $validator->errors()->add(
                            "phases.{$phaseIndex}.supplements.{$supplementIndex}.supplement_id",
                            'Een bestaand protocolsupplement kan niet naar een andere fase of template worden omgezet.',
                        );

                        continue;
                    }

                    if ($supplementId === null && ! $storedSelection) {
                        continue;
                    }

                    $catalogSupplement = $selectedSupplements->get($supplementId);
                    $supplementSettings = $storedSelection ?? $catalogSupplement;
                    if (! $supplementSettings) {
                        continue;
                    }

                    if (! $storedSelection
                        && $catalogSupplement->protocol_template_phase_id !== ($phase['protocol_template_phase_id'] ?? null)) {
                        $validator->errors()->add(
                            "phases.{$phaseIndex}.supplements.{$supplementIndex}.supplement_id",
                            'Het supplement moet bij de geselecteerde protocolfase horen.',
                        );
                    }

                    if (collect($supplement['week_numbers'] ?? [])->contains(
                        fn ($number): bool => (int) $number > (int) ($phase['week_count'] ?? 0),
                    )) {
                        $validator->errors()->add(
                            "phases.{$phaseIndex}.supplements.{$supplementIndex}.week_numbers",
                            'Supplementweken moeten binnen de protocolfase vallen.',
                        );
                    }

                    $timesPerWeek = (int) ($supplement['aantal_per_week'] ?? 0);
                    $weekNumbers = collect($supplement['week_numbers'] ?? [])->map(fn ($number): int => (int) $number)->sort()->values();

                    if ($weekNumbers->unique()->count() !== $weekNumbers->count()) {
                        $validator->errors()->add(
                            "phases.{$phaseIndex}.supplements.{$supplementIndex}.week_numbers",
                            'Elke supplementweek mag maar één keer voorkomen.',
                        );
                    }

                    if ($this->boolean('published') && $timesPerWeek < $supplementSettings->min_aantal_per_week) {
                        $validator->errors()->add(
                            "phases.{$phaseIndex}.supplements.{$supplementIndex}.aantal_per_week",
                            "Minimaal {$supplementSettings->min_aantal_per_week} keer per week vereist.",
                        );
                    }

                    if ($supplementSettings->max_aantal_in_fase !== null
                        && ($timesPerWeek * $weekNumbers->count()) > $supplementSettings->max_aantal_in_fase) {
                        $validator->errors()->add(
                            "phases.{$phaseIndex}.supplements.{$supplementIndex}.aantal_per_week",
                            "Het maximum van {$supplementSettings->max_aantal_in_fase} toedieningen in deze fase wordt overschreden.",
                        );
                    }
                }
            }

            if ($this->boolean('published')) {
                if (! $this->filled('started_at')) {
                    $validator->errors()->add('started_at', 'Een gepubliceerd protocol heeft een startdatum nodig.');
                }

                $submittedPhases = collect($this->input('phases', []));
                $missingRequiredDefinitions = $protocol
                    ? collect()
                    : ProtocolTemplatePhase::query()
                        ->where('protocol_template_id', $protocolTemplateId)
                        ->where('required', true)
                        ->whereNotIn('id', $selectedPhaseIds)
                        ->withCount('weeks')
                        ->get();

                if ($submittedPhases->isEmpty() && $missingRequiredDefinitions->isEmpty()) {
                    $validator->errors()->add('phases', 'Een gepubliceerd protocol heeft minimaal één fase nodig.');
                }

                foreach ($submittedPhases as $phaseIndex => $phase) {
                    if ((int) ($phase['week_count'] ?? 0) < 1) {
                        $validator->errors()->add(
                            "phases.{$phaseIndex}.week_count",
                            'Configureer minimaal één week voordat je deze fase publiceert.',
                        );
                    }
                }

                if ($missingRequiredDefinitions->contains(fn (ProtocolTemplatePhase $phase): bool => $phase->weeks_count < 1)) {
                    $validator->errors()->add('phases', 'Een verplichte templatefase heeft nog geen weken en kan daarom niet worden gepubliceerd.');
                }
            }

            if ($this->route('protocol') === null) {
                return;
            }

            $requiredPhaseIds = $protocol->phases()
                ->where('required', true)
                ->pluck('protocol_template_phase_id');
            $missingRequiredPhase = $requiredPhaseIds->diff($selectedPhaseIds)->isNotEmpty();

            if ($missingRequiredPhase) {
                $validator->errors()->add('phases', 'Required phases cannot be removed from a protocol.');
            }
        }];
    }
}
