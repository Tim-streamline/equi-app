<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use App\Models\IntakeSection;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'link_json' => ['nullable', 'string', 'max:20000'],
            'options_json' => ['nullable', 'string', 'max:50000'],
            'show_if_json' => ['nullable', 'string', 'max:50000'],
            'flag_if_json' => ['nullable', 'string', 'max:50000'],
            'critical_if_json' => ['nullable', 'string', 'max:50000'],
            'protocol_if_json' => ['nullable', 'string', 'max:50000'],
            'repeater_sub_json' => ['nullable', 'string', 'max:50000'],
        ]);

        foreach ([
            'link_json' => 'link',
            'options_json' => 'options',
            'show_if_json' => 'show_if',
            'flag_if_json' => 'flag_if',
            'critical_if_json' => 'critical_if',
            'protocol_if_json' => 'protocol_if',
            'repeater_sub_json' => 'repeater_sub',
        ] as $input => $column) {
            $data[$column] = $this->decodeJson($request->input($input), $input);
            unset($data[$input]);
        }

        $this->validateFieldJsonShapes($data);

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function validateFieldJsonShapes(array $data): void
    {
        $errors = [];

        if (! $this->isStringListOrNull($data['options'])) {
            $errors['options_json'] = 'Vul een JSON-array met antwoordteksten in.';
        }
        if (! $this->isTriggerMapOrNull($data['show_if'])) {
            $errors['show_if_json'] = 'Vul een JSON-object in met per veldsleutel één antwoord of een lijst antwoorden.';
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
            ->where('section_id', $field->section->key)
            ->where('field_id', $field->key)
            ->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'key' => 'Deze sleutel kan niet worden gewijzigd omdat er al antwoorden aan gekoppeld zijn.',
        ]);
    }
}
