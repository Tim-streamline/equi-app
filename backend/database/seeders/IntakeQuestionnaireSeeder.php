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
                        'minutes' => $sectionData['minutes'] ?? 0,
                        'icon' => $sectionData['icon'] ?? null,
                        'subtitle' => $sectionData['sub'] ?? null,
                        'active' => true,
                    ],
                );

                $fieldKeys = [];
                foreach ($sectionData['fields'] as $order => $fieldData) {
                    $fieldKeys[] = $fieldData['id'];
                    $section->fields()->updateOrCreate(
                        ['key' => $fieldData['id']],
                        [
                            'order' => $order,
                            'label' => $fieldData['label'],
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
                            'show_if' => $fieldData['showIf'] ?? null,
                            'flag_if' => $fieldData['flagIf'] ?? null,
                            'critical_if' => $fieldData['criticalIf'] ?? null,
                            'protocol_if' => $fieldData['protocolIf'] ?? null,
                            'repeater_sub' => $fieldData['sub'] ?? null,
                            'active' => true,
                        ],
                    );
                }

                $section->fields()->whereNotIn('key', $fieldKeys)->update(['active' => false]);
            }

            $questionnaire->sections()->whereNotIn('key', $sectionKeys)->update(['active' => false]);
        });
    }
}
