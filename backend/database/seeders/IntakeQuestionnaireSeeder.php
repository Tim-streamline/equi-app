<?php

namespace Database\Seeders;

use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IntakeQuestionnaireSeeder extends Seeder
{
    public function run(): void
    {
        $payload = json_decode(
            file_get_contents(database_path('data/intake-questionnaire.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        DB::transaction(function () use ($payload): void {
            $questionnaire = IntakeQuestionnaire::query()->updateOrCreate(
                ['slug' => $payload['slug']],
                [
                    'name' => $payload['name'],
                    'disclaimer_short' => $payload['disclaimer_short'],
                    'disclaimer_long' => $payload['disclaimer_long'],
                    'none_options' => $payload['none_options'],
                    'safety_config' => $payload['safety_config'] ?? null,
                    'active' => true,
                ],
            );

            $sectionKeys = [];
            foreach ($payload['sections'] as $sectionData) {
                $sectionKeys[] = $sectionData['id'];
                $section = $questionnaire->sections()->updateOrCreate(
                    ['key' => $sectionData['id']],
                    [
                        'order' => $sectionData['nr'],
                        'title' => $sectionData['title'],
                        'intro' => $sectionData['intro'] ?? null,
                        'presentation' => $sectionData['presentation'] ?? null,
                        'minutes' => $sectionData['minutes'] ?? 0,
                        'icon' => $sectionData['icon'] ?? null,
                        'subtitle' => $sectionData['sub'] ?? null,
                        'active' => true,
                    ],
                );

                $fieldKeys = [];
                foreach ($sectionData['fields'] as $order => $fieldData) {
                    $fieldKeys[] = $fieldData['id'];
                    \App\Models\IntakeField::withoutEvents(fn () => $section->fields()->updateOrCreate(
                        ['key' => $fieldData['id']],
                        [
                            'order' => $order,
                            'answer_section_key' => $fieldData['answerSection'] ?? null,
                            'label' => $fieldData['label'],
                            'photo_config' => $fieldData['photoConfig'] ?? null,
                            'age_config' => $fieldData['ageConfig'] ?? null,
                            'date_config' => $fieldData['dateConfig'] ?? null,
                            'type' => $fieldData['type'],
                            'hint' => $fieldData['hint'] ?? null,
                            'required' => $fieldData['required'] ?? false,
                            'optional' => $fieldData['optional'] ?? false,
                            'unit' => $fieldData['unit'] ?? null,
                            'step' => $fieldData['step'] ?? null,
                            'tall' => $fieldData['tall'] ?? false,
                            'lines' => $fieldData['lines'] ?? null,
                            'placeholder' => $fieldData['placeholder'] ?? null,
                            'link' => $fieldData['link'] ?? null,
                            'options' => $fieldData['options'] ?? null,
                            'option_effects' => $fieldData['optionEffects'] ?? null,
                            'option_keys' => $fieldData['optionKeys'] ?? [],
                            'answer_aliases' => $fieldData['answerAliases'] ?? null,
                            'submission_blocks' => $fieldData['submissionBlocks'] ?? null,
                            'show_if' => $fieldData['showIf'] ?? null,
                            'critical_if' => $fieldData['criticalIf'] ?? null,
                            'protocol_if' => $fieldData['protocolIf'] ?? null,
                            'signal_config' => $fieldData['signalConfig'] ?? null,
                            'repeater_sub' => $fieldData['sub'] ?? null,
                            'active' => true,
                        ],
                    ));
                }

                $section->fields()->whereNotIn('key', $fieldKeys)->update(['active' => false]);
            }

            $questionnaire->sections()->whereNotIn('key', $sectionKeys)->update(['active' => false]);
            // Resolve against the complete definition, including parents declared in later sections.
            $conditions = app(\App\Support\IntakeAnswerConditions::class);
            $sections = $questionnaire->sections()->with('fields')->get();
            $catalog = $conditions->catalog($sections);
            foreach ($sections as $section) {
                foreach ($section->fields as $field) {
                    $rule = $conditions->migrate($field->show_if, $section->key, $catalog, $questionnaire->none_options ?? []);
                    DB::table('intake_fields')->where('id', $field->id)->update(['visibility_rule' => $rule === null ? null : json_encode($rule, JSON_THROW_ON_ERROR)]);
                }
            }
        });
    }
}
