<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use App\Models\IntakeSection;
use App\Support\AuditLogger;
use App\Support\IntakeSignalPreparation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;

class IntakeQuestionnaireController extends Controller
{
    private const FIELD_TYPES = [
        'text',
        'textarea',
        'number',
        'date',
        'month_year',
        'radio',
        'multi',
        'photo',
        'file',
        'repeater',
        'sectionhead',
    ];

    public function index(): Response
    {
        $questionnaire = IntakeQuestionnaire::query()
            ->with(['sections' => fn ($query) => $query->orderBy('order'), 'sections.fields' => fn ($query) => $query->orderBy('order')])
            ->where('active', true)
            ->firstOrFail();

        return Inertia::render('IntakeQuestionnaire/Index', [
            'questionnaire' => $questionnaire,
            'fieldTypes' => self::FIELD_TYPES,
        ]);
    }

    public function updateQuestionnaire(Request $request, IntakeQuestionnaire $intakeQuestionnaire): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'disclaimer_short' => ['nullable', 'string', 'max:10000'],
            'disclaimer_long' => ['nullable', 'string', 'max:30000'],
            'none_options_json' => ['required', 'string', 'max:20000'],
            'safety_config_json' => ['sometimes', 'nullable', 'string', 'max:50000'],
        ]);
        $data['none_options'] = $this->decodeJson($data['none_options_json'], 'none_options_json');
        unset($data['none_options_json']);
        if (! is_array($data['none_options'])
            || ! array_is_list($data['none_options'])
            || array_filter($data['none_options'], fn ($value) => ! is_string($value))) {
            throw ValidationException::withMessages([
                'none_options_json' => 'Vul een JSON-array met antwoordteksten in.',
            ]);
        }
        if ($request->exists('safety_config_json')) {
            $data['safety_config'] = $this->decodeJson($request->input('safety_config_json'), 'safety_config_json');
            try { app(\App\Support\IntakeSafetySettings::class)->validate($data['safety_config']); }
            catch (ValidationException $e) { throw ValidationException::withMessages(['safety_config_json' => implode(' ', $e->validator->errors()->all())]); }
            unset($data['safety_config_json']);
        }
        $before = $intakeQuestionnaire->only(array_keys($data));
        $intakeQuestionnaire->update($data);
        AuditLogger::updated($intakeQuestionnaire, $before);

        return back()->with('success', 'Intake-instellingen opgeslagen.');
    }

    public function storeSection(Request $request): RedirectResponse
    {
        $data = $this->sectionData($request);
        $section = IntakeSection::query()->create($data);
        AuditLogger::created($section);

        return back()->with('success', 'Intakesectie toegevoegd.');
    }

    public function updateSection(Request $request, IntakeSection $intakeSection): RedirectResponse
    {
        $data = $this->sectionData($request, $intakeSection);
        $this->ensureSectionKeyCanChange($intakeSection, $data['key']);
        $before = $intakeSection->only(array_keys($data));
        $intakeSection->update($data);
        AuditLogger::updated($intakeSection, $before);

        return back()->with('success', 'Intakesectie opgeslagen.');
    }

    public function destroySection(IntakeSection $intakeSection): RedirectResponse
    {
        $before = $intakeSection->only(['active']);
        DB::transaction(function () use ($intakeSection): void {
            $intakeSection->update(['active' => false]);
            $intakeSection->fields()->update(['active' => false]);
        });
        AuditLogger::updated($intakeSection, $before);

        return back()->with('success', 'Intakesectie gearchiveerd. Bestaande antwoorden blijven bewaard.');
    }

    public function storeField(Request $request): RedirectResponse
    {
        $data = $this->fieldData($request);
        $field = IntakeField::query()->create($data);
        AuditLogger::created($field);

        return back()->with('success', 'Intakeveld toegevoegd.');
    }

    public function updateField(Request $request, IntakeField $intakeField): RedirectResponse
    {
        $data = $this->fieldData($request, $intakeField);
        $this->ensureFieldKeyCanChange($intakeField, $data['key']);
        $before = $intakeField->only(array_keys($data));
        $intakeField->update($data);
        AuditLogger::updated($intakeField, $before);

        return back()->with('success', 'Intakeveld opgeslagen.');
    }

    public function destroyField(IntakeField $intakeField): RedirectResponse
    {
        $before = $intakeField->only(['active']);
        $intakeField->update(['active' => false]);
        AuditLogger::updated($intakeField, $before);

        return back()->with('success', 'Intakeveld gearchiveerd. Bestaande antwoorden blijven bewaard.');
    }

    /** @return array<string, mixed> */
    private function sectionData(Request $request, ?IntakeSection $section = null): array
    {
        return $request->validate([
            'questionnaire_id' => ['required', 'uuid', 'exists:intake_questionnaires,id'],
            'key' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('intake_sections', 'key')
                    ->where('questionnaire_id', $request->input('questionnaire_id'))
                    ->ignore($section?->id),
            ],
            'title' => ['required', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:10000'],
            'presentation' => ['sometimes', 'nullable', 'array:intro_style,offline_notice'],
            'presentation.intro_style' => ['sometimes', 'in:plain,card'],
            'presentation.offline_notice' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:32'],
            'minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'order' => ['required', 'integer', 'min:0', 'max:10000'],
            'active' => ['required', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function fieldData(Request $request, ?IntakeField $field = null): array
    {
        $data = $request->validate([
            'section_id' => ['required', 'uuid', 'exists:intake_sections,id'],
            'key' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('intake_fields', 'key')
                    ->where('section_id', $request->input('section_id'))
                    ->ignore($field?->id),
            ],
            'label' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(self::FIELD_TYPES)],
            'hint' => ['nullable', 'string', 'max:10000'],
            'required' => ['required', 'boolean'],
            'optional' => ['required', 'boolean'],
            'unit' => ['nullable', 'string', 'max:32'],
            'step' => ['nullable', 'numeric'],
            'tall' => ['required', 'boolean'],
            'lines' => ['nullable', 'integer', 'min:1', 'max:50'],
            'placeholder' => ['nullable', 'string', 'max:1000'],
            'order' => ['required', 'integer', 'min:0', 'max:10000'],
            'active' => ['required', 'boolean'],
            'photo_config_json' => ['nullable', 'string', 'max:20000'],
            'date_config_json' => ['nullable', 'string', 'max:20000'],
            'age_config_json' => ['nullable', 'string', 'max:20000'],
            'link_json' => ['nullable', 'string', 'max:20000'],
            'options_json' => ['nullable', 'string', 'max:50000'],
            'answer_aliases_json' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'submission_blocks' => ['sometimes', 'nullable', 'array'],
            'show_if_json' => ['nullable', 'string', 'max:50000'],
            'flag_if_json' => ['nullable', 'string', 'max:50000'],
            'critical_if_json' => ['nullable', 'string', 'max:50000'],
            'protocol_if_json' => ['nullable', 'string', 'max:50000'],
            'signal_config_json' => ['nullable', 'string', 'max:50000'],
            'repeater_sub_json' => ['nullable', 'string', 'max:50000'],
        ]);

        foreach ([
            'photo_config_json' => 'photo_config',
            'age_config_json' => 'age_config',
            'date_config_json' => 'date_config',
            'link_json' => 'link',
            'options_json' => 'options',
            'answer_aliases_json' => 'answer_aliases',
            'show_if_json' => 'show_if',
            'flag_if_json' => 'flag_if',
            'critical_if_json' => 'critical_if',
            'protocol_if_json' => 'protocol_if',
            'signal_config_json' => 'signal_config',
            'repeater_sub_json' => 'repeater_sub',
        ] as $input => $column) {
            $data[$column] = $this->decodeJson($request->input($input), $input);
            unset($data[$input]);
        }

        if (! $request->exists('answer_aliases_json')) $data['answer_aliases'] = $field?->answer_aliases;
        $data['submission_blocks'] = $request->exists('submission_blocks') ? $request->input('submission_blocks') : $field?->submission_blocks;
        try { app(\App\Support\IntakeSubmissionBlocks::class)->validate($data['submission_blocks']); }
        catch (ValidationException $e) { throw ValidationException::withMessages(['submission_blocks' => implode(' ', $e->validator->errors()->all())]); }
        try { app(\App\Support\IntakeSafetySettings::class)->validateAliases($data['answer_aliases']); }
        catch (ValidationException $e) { throw ValidationException::withMessages(['answer_aliases_json' => implode(' ', $e->validator->errors()->all())]); }
        if (! $request->exists('date_config_json')) $data['date_config'] = $field?->date_config;
        try {
            app(\App\Support\IntakeDates::class)->validateConfig($data['date_config']);
            foreach (is_array($data['repeater_sub'] ?? null) ? $data['repeater_sub'] : [] as $sub) app(\App\Support\IntakeDates::class)->validateConfig(is_array($sub) ? ($sub['dateConfig'] ?? null) : null);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['date_config_json' => implode(' ', $e->validator->errors()->all())]);
        }

        // Older admin forms may omit photo settings; ordinary copy edits must retain them.
        if (! $request->exists('photo_config_json')) {
            $data['photo_config'] = $field?->photo_config;
        }
        if (! $request->exists('age_config_json')) {
            $data['age_config'] = $field?->age_config;
        }
        if (! $request->exists('signal_config_json')) {
            $data['signal_config'] = $field?->signal_config;
        }
        try {
            app(IntakeSignalPreparation::class)->validateConfig($data['signal_config']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['signal_config_json' => implode(' ', $exception->validator->errors()->all())]);
        }
        if ($data['signal_config'] !== null && ! in_array($data['type'], ['text', 'textarea'], true)) {
            throw ValidationException::withMessages(['signal_config_json' => 'Signaalvoorbereiding is alleen beschikbaar voor open tekstvragen.']);
        }
        if ($data['age_config'] !== null) {
            $validator = Validator::make(['age' => $data['age_config']], [
                'age' => ['required', 'array:date_field,date_label,toggle_label,calculated_hint,estimate_label'],
                'age.date_field' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
                'age.date_label' => ['required', 'string', 'max:255'],
                'age.toggle_label' => ['required', 'string', 'max:255'],
                'age.calculated_hint' => ['nullable', 'string', 'max:255'],
                'age.estimate_label' => ['required', 'string', 'max:255'],
            ]);
            if ($validator->fails()) {
                throw ValidationException::withMessages(['age_config_json' => implode(' ', $validator->errors()->all())]);
            }
            if ($data['age_config']['date_field'] === $data['key']) {
                throw ValidationException::withMessages(['age_config_json' => 'Leeftijd en geboortedatum moeten verschillende opslagsleutels hebben.']);
            }
            if ($field?->age_config && $field->age_config['date_field'] !== $data['age_config']['date_field']) {
                throw ValidationException::withMessages(['age_config_json' => 'Behoud de bestaande geboortedatum-sleutel om opgeslagen antwoorden te behouden.']);
            }
        }
        $this->validateFieldJsonShapes($data);
        if ($data['photo_config'] !== null) {
            $validator = Validator::make(['photo' => $data['photo_config']], [
                'photo' => ['array:max,year_required,allow_na,na_key,slots,grouped_hooves,legacy_field,existing_only,example_url,example_caption,example_after,caption,instruction_label,instructions,document_layout'],
                'photo.document_layout' => ['sometimes', 'in:full_page'],
                'photo.max' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'photo.year_required' => ['sometimes', 'boolean'],
                'photo.allow_na' => ['sometimes', 'boolean'],
                'photo.na_key' => ['sometimes', 'string', 'max:128', 'regex:/^[a-zA-Z0-9:_-]+$/'],
                'photo.existing_only' => ['sometimes', 'boolean'],
                'photo.slots' => ['sometimes', 'array', 'list', 'max:24'],
                'photo.slots.*' => ['array:id,label,answer_field,answer_slot'],
                'photo.slots.*.id' => ['required', 'string', 'distinct', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
                'photo.slots.*.label' => ['required', 'string', 'max:255'],
                'photo.slots.*.answer_field' => ['sometimes', 'required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
                'photo.slots.*.answer_slot' => ['sometimes', 'required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
                'photo.grouped_hooves' => ['sometimes', 'array', 'list', 'size:4'],
                'photo.grouped_hooves.*' => ['array:field,label,slots'],
                'photo.grouped_hooves.*.field' => ['required', 'string', 'distinct', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
                'photo.grouped_hooves.*.label' => ['required', 'string', 'max:255'],
                'photo.grouped_hooves.*.slots' => ['required', 'array', 'list', 'size:3'],
                'photo.grouped_hooves.*.slots.*' => ['array:id,label'],
                'photo.grouped_hooves.*.slots.*.id' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
                'photo.grouped_hooves.*.slots.*.label' => ['required', 'string', 'max:255'],
                'photo.legacy_field' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
                'photo.caption' => ['sometimes', 'array:label,placeholder'],
                'photo.caption.label' => ['required_with:photo.caption', 'string', 'max:255'],
                'photo.caption.placeholder' => ['sometimes', 'nullable', 'string', 'max:255'],
                'photo.instruction_label' => ['sometimes', 'nullable', 'string', 'max:255'],
                'photo.instructions' => ['sometimes', 'nullable', 'string', 'max:10000'],
                'photo.example_after' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'photo.example_url' => ['sometimes', 'string', 'max:2000', 'regex:~^(https://[^/]+/|/(?!/))~'],
                'photo.example_caption' => ['sometimes', 'string', 'max:255'],
            ]);
            if ($validator->fails()) {
                throw ValidationException::withMessages(['photo_config_json' => implode(' ', $validator->errors()->all())]);
            }
            if (isset($data['photo_config']['max']) && count($data['photo_config']['slots'] ?? []) > $data['photo_config']['max']) {
                throw ValidationException::withMessages(['photo_config_json' => 'Het maximum mag niet lager zijn dan het aantal foto-aanzichten.']);
            }
        }

        // Moving a question frees its presentation key, not its stored answer
        // namespace. Do not let a new question reuse those historical answers.
        $section = IntakeSection::findOrFail($data['section_id']);
        $answerSection = $field ? ($field->answer_section_key ?? $field->section->key) : $section->key;
        if ($field && $field->section_id !== $section->id) {
            if ($field->section->questionnaire_id !== $section->questionnaire_id) {
                throw ValidationException::withMessages(['section_id' => 'Verplaats de vraag binnen dezelfde intake.']);
            }
            $data['answer_section_key'] = $answerSection;
            $parents = $field->section->fields->keyBy('key');
            $origin = $field->section->key;
            $data['show_if'] = app(\App\Support\IntakeConditions::class)->qualify($data['show_if'], fn ($key) => $parents->get($key)?->answer_section_key ?? $origin);
        }
        if (IntakeField::where('key', $data['key'])->when($field, fn ($q) => $q->whereKeyNot($field->id))
            ->whereHas('section', fn ($q) => $q->where('questionnaire_id', $section->questionnaire_id))
            ->where(fn ($q) => $q->where('answer_section_key', $answerSection)
                ->orWhere(fn ($q) => $q->whereNull('answer_section_key')->whereHas('section', fn ($q) => $q->where('key', $answerSection))))->exists()) {
            throw ValidationException::withMessages(['key' => 'Deze sleutel is al gekoppeld aan een intakevraag en de opgeslagen antwoorden.']);
        }

        if ($data['age_config'] !== null && IntakeField::where('key', $data['age_config']['date_field'])
            ->where('active', true)->whereHas('section', fn ($q) => $q->where('questionnaire_id', $section->questionnaire_id))
            ->where(fn ($q) => $q->where('answer_section_key', $answerSection)
                ->orWhere(fn ($q) => $q->whereNull('answer_section_key')->whereHas('section', fn ($q) => $q->where('key', $answerSection))))->exists()) {
            throw ValidationException::withMessages(['age_config_json' => 'Deze geboortedatum-sleutel is al gekoppeld aan een actieve vraag.']);
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function validateFieldJsonShapes(array $data): void
    {
        $errors = [];

        if (! $this->isStringListOrNull($data['options'])) {
            $errors['options_json'] = 'Vul een JSON-array met antwoordteksten in.';
        }
        if (! app(\App\Support\IntakeConditions::class)->valid($data['show_if'])) {
            $errors['show_if_json'] = 'Gebruik veldsleutels met antwoorden, of groepen $all (alles), $any (minstens één) en $not (niet).';
        }
        foreach (['flag_if' => 'flag_if_json', 'critical_if' => 'critical_if_json'] as $column => $input) {
            if (! $this->isTriggerOrNull($data[$column])) {
                $errors[$input] = 'Vul één antwoordtekst of een JSON-array met antwoordteksten in.';
            }
        }
        if ($data['protocol_if'] !== null && (! is_array($data['protocol_if']) || array_is_list($data['protocol_if']))) {
            $errors['protocol_if_json'] = 'Vul een JSON-object in.';
        }
        if ($data['link'] !== null && (! is_array($data['link'])
            || ! is_string($data['link']['text'] ?? null)
            || ! is_string($data['link']['url'] ?? null))) {
            $errors['link_json'] = 'Vul een JSON-object met text en url in.';
        }
        if ($data['repeater_sub'] !== null && (! is_array($data['repeater_sub'])
            || ! array_is_list($data['repeater_sub'])
            || array_filter($data['repeater_sub'], fn ($sub) => ! is_array($sub)
                || ! is_string($sub['id'] ?? null)
                || ! is_string($sub['label'] ?? null)
                || ! is_string($sub['type'] ?? null)))) {
            $errors['repeater_sub_json'] = 'Vul een JSON-array met subvelden met id, label en type in.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function isStringListOrNull(mixed $value): bool
    {
        return $value === null || (is_array($value)
            && array_is_list($value)
            && array_filter($value, fn ($item) => ! is_string($item)) === []);
    }

    private function isTriggerOrNull(mixed $value): bool
    {
        return $value === null || is_string($value) || $this->isStringListOrNull($value);
    }

    private function isTriggerMapOrNull(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (! is_array($value) || array_is_list($value)) {
            return false;
        }

        foreach ($value as $trigger) {
            if (! $this->isTriggerOrNull($trigger) || $trigger === null) {
                return false;
            }
        }

        return true;
    }

    private function decodeJson(mixed $value, string $field): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([$field => 'Vul geldige JSON in.']);
        }
    }

    private function ensureSectionKeyCanChange(IntakeSection $section, string $newKey): void
    {
        if ($section->key === $newKey || ! DB::table('intake_answers')->where('section_id', $section->key)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'key' => 'Deze sleutel kan niet worden gewijzigd omdat er al antwoorden aan gekoppeld zijn.',
        ]);
    }

    private function ensureFieldKeyCanChange(IntakeField $field, string $newKey): void
    {
        if ($field->key === $newKey || ! DB::table('intake_answers')
            ->where('section_id', $field->answer_section_key ?? $field->section->key)
            ->where('field_id', $field->key)
            ->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'key' => 'Deze sleutel kan niet worden gewijzigd omdat er al antwoorden aan gekoppeld zijn.',
        ]);
    }
}
