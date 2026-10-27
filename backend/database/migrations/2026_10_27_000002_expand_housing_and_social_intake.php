<?php

use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $patch = json_decode(file_get_contents(database_path('data/housing-social-2026-10-27.json')), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($patch): void {
            $q = IntakeQuestionnaire::where('slug', 'protocol-intake')->first();
            if (! $q) return;
            foreach ($patch['sections'] as $sectionData) {
                $section = $q->sections()->where('key', $sectionData['id'])->first();
                if (! $section) continue;
                foreach ($sectionData['fields'] as $change) {
                    $data = $change['definition'];
                    $field = $section->fields()->where('key', $data['id'])->first();
                    if ($change['existing']) {
                        if (! $field) continue;
                        // Only revise the explicitly requested settings. Never rewrite responses.
                        $updates = ['label' => $data['label']];
                        foreach (['showIf' => 'show_if', 'options' => 'options', 'flagIf' => 'flag_if', 'answerAliases' => 'answer_aliases'] as $key => $column) {
                            if (array_key_exists($key, $data)) $updates[$column] = $data[$key];
                        }
                        $field->update($updates);
                        continue;
                    }
                    // Idempotent and never reset a new question already customised by an administrator.
                    if ($field) continue;
                    $anchor = $section->fields()->where('key', $change['after'])->first();
                    $order = $anchor ? $anchor->order + 1 : ((int) $section->fields()->max('order') + 1);
                    $section->fields()->where('order', '>=', $order)->increment('order');
                    $section->fields()->create([
                        'key' => $data['id'], 'order' => $order, 'label' => $data['label'], 'type' => $data['type'],
                        'required' => $data['required'] ?? false, 'optional' => $data['optional'] ?? false,
                        'hint' => $data['hint'] ?? null, 'placeholder' => $data['placeholder'] ?? null,
                        'unit' => $data['unit'] ?? null, 'step' => $data['step'] ?? null, 'lines' => $data['lines'] ?? null,
                        'options' => $data['options'] ?? null, 'show_if' => $data['showIf'] ?? null,
                        'flag_if' => $data['flagIf'] ?? null, 'photo_config' => $data['photoConfig'] ?? null,
                        'active' => true,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Saved answers, uploads and subsequent admin edits must survive a rollback.
    }
};
