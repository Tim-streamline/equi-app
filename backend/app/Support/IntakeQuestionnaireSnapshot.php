<?php

namespace App\Support;

use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use App\Models\IntakeSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/** Definition-only snapshots: never write bookings, answers, attachments or files. */
class IntakeQuestionnaireSnapshot
{
    public const QUESTIONNAIRE = ['slug', 'name', 'disclaimer_short', 'disclaimer_long', 'none_options', 'safety_config', 'active'];

    public const SECTION = ['key', 'order', 'title', 'intro', 'presentation', 'minutes', 'icon', 'subtitle', 'active'];

    public const FIELD = ['key', 'order', 'answer_section_key', 'label', 'type', 'hint', 'required', 'optional', 'unit', 'step', 'tall', 'lines', 'placeholder', 'link', 'options', 'answer_aliases', 'show_if', 'flag_if', 'critical_if', 'protocol_if', 'repeater_sub', 'photo_config', 'age_config', 'signal_config', 'date_config', 'active'];

    public function assertSchema(): void
    {
        foreach (['intake_questionnaires' => self::QUESTIONNAIRE, 'intake_sections' => self::SECTION, 'intake_fields' => self::FIELD] as $table => $columns) {
            $missing = array_diff($columns, Schema::getColumnListing($table));
            if ($missing) {
                throw new RuntimeException("Apply the intake schema migrations first; {$table} is missing: ".implode(', ', $missing));
            }
        }
    }

    public function export(string $slug = 'protocol-intake'): array
    {
        $this->assertSchema();
        $q = IntakeQuestionnaire::where('slug', $slug)->with('sections.fields')->sole();

        return $this->snapshot($q);
    }

    private function snapshot(IntakeQuestionnaire $q): array
    {
        return [
            'format' => 1,
            'questionnaire' => $q->only(self::QUESTIONNAIRE),
            'sections' => $q->sections->sortBy('key')->map(fn ($s) => [
                ...$s->only(self::SECTION),
                'fields' => $s->fields->sortBy('key')->map(fn ($f) => $f->only(self::FIELD))->values()->all(),
            ])->values()->all(),
        ];
    }

    public function validate(array $data): void
    {
        Validator::make($data, [
            'format' => ['required', 'integer', 'in:1'],
            'questionnaire' => ['required', 'array:'.implode(',', self::QUESTIONNAIRE)],
            'questionnaire.slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'questionnaire.name' => ['required', 'string', 'max:255'],
            'questionnaire.active' => ['required', 'boolean'],
            'questionnaire.none_options' => ['nullable', 'array'],
            'questionnaire.none_options.*' => ['string'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['required', 'array:'.implode(',', [...self::SECTION, 'fields'])],
            'sections.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'distinct:strict'],
            'sections.*.title' => ['required', 'string', 'max:255'],
            'sections.*.order' => ['required', 'integer', 'min:0'],
            'sections.*.minutes' => ['required', 'integer', 'min:0'],
            'sections.*.active' => ['required', 'boolean'],
            'sections.*.fields' => ['present', 'array'],
            'sections.*.fields.*' => ['required', 'array:'.implode(',', self::FIELD)],
            'sections.*.fields.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'distinct:strict'],
            'sections.*.fields.*.answer_section_key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'sections.*.fields.*.label' => ['required', 'string', 'max:255'],
            'sections.*.fields.*.type' => ['required', 'in:text,textarea,number,date,month_year,radio,multi,photo,file,repeater,sectionhead'],
            'sections.*.fields.*.order' => ['required', 'integer', 'min:0'],
            'sections.*.fields.*.active' => ['required', 'boolean'],
            'sections.*.fields.*.required' => ['required', 'boolean'],
            'sections.*.fields.*.optional' => ['required', 'boolean'],
            'sections.*.fields.*.tall' => ['required', 'boolean'],
        ])->validate();
        $this->requireColumns($data['questionnaire'], array_diff(self::QUESTIONNAIRE, ['safety_config']));
        app(IntakeSafetySettings::class)->validate($data['questionnaire']['safety_config'] ?? null);
        $fields = 0;
        foreach ($data['sections'] as $section) {
            $this->requireColumns($section, self::SECTION);
            foreach ($section['fields'] as $field) {
                // Older snapshots may omit this new optional setting; preserve it on import.
                $this->requireColumns($field, array_diff(self::FIELD, ['signal_config', 'date_config', 'answer_aliases']));
                app(IntakeSafetySettings::class)->validateAliases($field['answer_aliases'] ?? null);
                app(IntakeSignalPreparation::class)->validateConfig($field['signal_config'] ?? null);
                app(IntakeDates::class)->validateConfig($field['date_config'] ?? null);
                foreach ($field['repeater_sub'] ?? [] as $sub) app(IntakeDates::class)->validateConfig($sub['dateConfig'] ?? null);
                if (($field['signal_config'] ?? null) !== null && ! in_array($field['type'], ['text', 'textarea'], true)) {
                    throw new RuntimeException('Signal preparation requires a text field.');
                }
                $fields++;
            }
        }
        if (! $fields) {
            throw new RuntimeException('Refusing an empty questionnaire snapshot.');
        }
    }

    private function requireColumns(array $row, array $columns): void
    {
        if ($missing = array_diff($columns, array_keys($row))) {
            throw new RuntimeException('Incomplete snapshot; missing settings: '.implode(', ', $missing));
        }
    }

    public static function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /** Stable JSON object order, but preserve option and slot array order. */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => $this->canonical($v), $value);
    }

    private function changes(Model $model, array $desired): array
    {
        $candidate = clone $model;
        $candidate->forceFill($desired);

        return array_filter($desired, fn ($v, $k) => $this->canonical($model->getAttribute($k)) !== $this->canonical($candidate->getAttribute($k)), ARRAY_FILTER_USE_BOTH);
    }

    public function sync(array $data, bool $apply = false, ?string $backupDirectory = null): array
    {
        $this->validate($data);
        $this->assertSchema();

        return DB::transaction(function () use ($data, $apply, $backupDirectory) {
            if (! $apply && DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            // Serialize configuration changes, including concurrent admin inserts.
            // Customer answer/upload tables remain unlocked and are never modified.
            if ($apply && DB::getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL lock_timeout = '10s'");
                DB::statement('LOCK TABLE intake_questionnaires, intake_sections, intake_fields IN SHARE ROW EXCLUSIVE MODE');
            }
            $q = IntakeQuestionnaire::where('slug', $data['questionnaire']['slug'])->with('sections.fields')->sole();
            $sections = $q->sections->keyBy('key');
            $existing = $q->sections->flatMap(fn ($s) => $s->fields);
            $duplicates = $existing->groupBy('key')->filter(fn ($group) => $group->count() > 1)->keys();
            if ($duplicates->isNotEmpty()) {
                throw new RuntimeException('Ambiguous existing field keys; reconcile explicitly first: '.$duplicates->implode(', '));
            }
            $fields = $existing->keyBy('key');
            $operations = [];
            $warnings = [];
            $plan = function (Model $model, array $attributes, string $name, string $action = 'update') use (&$operations): void {
                if (! $model->exists || $this->changes($model, $attributes)) {
                    $operations[] = ['model' => $model, 'attributes' => $attributes, 'name' => $name, 'action' => $model->exists ? $action : 'create', 'settings' => array_keys($this->changes($model, $attributes))];
                }
            };
            $plan($q, $data['questionnaire'], 'questionnaire/'.$q->slug);
            $wantedSections = [];
            $wantedFields = [];
            foreach ($data['sections'] as $sectionData) {
                $wantedSections[] = $sectionData['key'];
                $section = $sections->get($sectionData['key']) ?? (new IntakeSection)->forceFill(['id' => (string) Str::uuid(), 'questionnaire_id' => $q->id]);
                $plan($section, Arr::only($sectionData, self::SECTION), 'section/'.$sectionData['key']);
                foreach ($sectionData['fields'] as $fieldData) {
                    $wantedFields[] = $fieldData['key'];
                    $field = $fields->get($fieldData['key']) ?? new IntakeField;
                    if ($field->exists) {
                        $oldSection = $q->sections->firstWhere('id', $field->section_id)->key;
                        $oldAddress = $field->answer_section_key ?? $oldSection;
                        $newAddress = $fieldData['answer_section_key'] ?? $sectionData['key'];
                        if ($oldAddress !== $newAddress) {
                            throw new RuntimeException("Answer location would change for {$field->key}: {$oldAddress} -> {$newAddress}. Preserve answer_section_key in the source snapshot; no answers were moved.");
                        }
                        // Even an apparently unanswered field may have offline writes queued.
                        if ($field->type !== $fieldData['type']) {
                            throw new RuntimeException("Field type would change for {$field->key}; an explicit answer migration is required.");
                        }
                        if ($this->changes($field, $fieldData)) {
                            $count = DB::table('intake_answers')->where('section_id', $oldAddress)->where('field_id', $field->key)->count();
                            if ($count) {
                                $warnings[] = "{$field->key}: {$count} saved answer(s) retained verbatim; changed choices, visibility or requirements may need user review.";
                            }
                        }
                    }
                    $plan($field, [...$fieldData, 'section_id' => $section->id], 'field/'.$fieldData['key']);
                }
            }
            foreach ($fields as $key => $field) {
                if (! in_array($key, $wantedFields, true) && $field->active) {
                    $plan($field, ['active' => false], 'field/'.$key, 'archive');
                    $warnings[] = "{$key}: absent from snapshot; archive definition only. Saved responses remain stored but this question leaves the active form.";
                }
            }
            foreach ($sections as $key => $section) {
                if (! in_array($key, $wantedSections, true) && $section->active) {
                    $plan($section, ['active' => false], 'section/'.$key, 'archive');
                }
            }
            $backup = null;
            if ($apply && $operations) {
                $directory = $backupDirectory ?? storage_path('app/private/intake-questionnaire-backups');
                if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                    throw new RuntimeException('Cannot create backup directory.');
                }
                $backup = $directory.'/'.now()->format('Ymd-His').'-'.Str::uuid().'.json';
                $before = $this->snapshot($q);
                // Extra metadata is for recovery/audit only, never imported as IDs.
                $before['backup_records'] = ['questionnaire' => $q->getAttributes(), 'sections' => $q->sections->map->getAttributes()->all(), 'fields' => $existing->map->getAttributes()->all()];
                $handle = fopen($backup, 'x');
                if ($handle === false) {
                    throw new RuntimeException('Cannot create backup file; nothing applied.');
                }
                try {
                    $json = self::json($before);
                    if (! chmod($backup, 0600) || fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                        throw new RuntimeException('Cannot finish backup; nothing applied.');
                    }
                } finally {
                    fclose($handle);
                }
                foreach ($operations as $op) {
                    $op['model']->forceFill($op['attributes'])->save();
                }
                // Verify both exact imported definitions and archived target-only rows.
                foreach ($operations as $op) {
                    if ($this->changes($op['model']->fresh(), $op['attributes'])) {
                        throw new RuntimeException('Verification failed for '.$op['name'].'; transaction rolled back.');
                    }
                }
            }

            return ['changes' => array_map(fn ($op) => Arr::except($op, ['model', 'attributes']), $operations), 'warnings' => $warnings, 'backup' => $backup];
        });
    }
}
