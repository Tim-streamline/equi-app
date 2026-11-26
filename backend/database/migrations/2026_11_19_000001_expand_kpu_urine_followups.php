<?php

use App\Models\IntakeField;
use App\Support\IntakeConditions;
use App\Support\IntakeDates;
use App\Support\IntakeLabels;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $plan = json_decode(file_get_contents(database_path('data/kpu-followups-2026-11-19.json')), true, flags: JSON_THROW_ON_ERROR);
            $keys = [...array_column($plan['fields'], 'id'), 'kpu-status', 'kpu-onderzoek-uitslag', 'kpu-uitslag-beschikbaar', 'kpu-attest'];
            $fields = IntakeField::with('section')->whereIn('key', $keys)
                ->whereHas('section.questionnaire', fn ($q) => $q->where('slug', 'protocol-intake'))
                ->get()->filter(fn ($field) => ($field->answer_section_key ?? $field->section->key) === 'medisch')->keyBy('key');
            $status = $fields->get('kpu-status');
            if (! $status) return;
            $section = $status->section;
            $visibility = static function (IntakeField $field, array $rule) use ($fields): array {
                // A moved question still reads the original medical answers, including their aliases.
                if ($field->section->key === 'medisch' && collect(array_keys($rule))->every(fn ($key) => $fields->get($key)?->section->key === 'medisch')) return $rule;
                return app(IntakeConditions::class)->qualify($rule, fn () => 'medisch', fn ($key) => $fields->get($key)?->answer_aliases ?? []);
            };
            // Include the current admin wording; keep all existing options and answer values.
            $positive = array_values(array_unique(array_merge($plan['positive'], array_values(array_filter($status->options ?? [],
                fn ($option) => preg_match('/^(ja\b|aangetoond).*urine/iu', $option))))));
            $orderedIds = [];
            foreach ($plan['fields'] as $definition) {
                $key = $definition['id'];
                $field = $fields->get($key) ?? $section->fields()->make(['key' => $key, 'order' => 0,
                    'answer_section_key' => $section->key === 'medisch' ? null : 'medisch']);
                $attributes = ['label' => $definition['label'], 'type' => $definition['type'],
                    'required' => true, 'optional' => false, 'active' => true,
                    'show_if' => $visibility($field, ['kpu-status' => $positive] + ($definition['showIf'] ?? []))];
                foreach (['hint' => 'hint', 'placeholder' => 'placeholder', 'options' => 'options', 'dateConfig' => 'date_config'] as $from => $to) {
                    if (array_key_exists($from, $definition)) $attributes[$to] = $definition[$from];
                }
                $field->fill($attributes)->save();
                $fields->put($key, $field);
                if ($field->section_id === $section->id) $orderedIds[] = $field->id;
                $field->update(['label_config' => app(IntakeLabels::class)->complete($field->toArray(), 'medisch_'.$key, $field->label_config)]);
                if ($definition['type'] === 'month_year') {
                    // Keep existing date answers readable after switching to month/year controls.
                    DB::table('intake_answers')->where('section_id', $field->answer_section_key ?? 'medisch')->where('field_id', $key)
                        ->orderBy('id')->chunkById(200, function ($answers) use ($field): void {
                            foreach ($answers as $answer) {
                                $value = json_decode($answer->value ?? 'null', true);
                                $normalized = app(IntakeDates::class)->forField($field, $value);
                                if ($normalized !== $value) DB::table('intake_answers')->where('id', $answer->id)->update(['value' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                            }
                        });
                }
            }
            // Retain old combined results for submitted intakes; don't ask the same result twice.
            $fields->get('kpu-onderzoek-uitslag')?->update(['active' => false]);
            foreach (['kpu-uitslag-beschikbaar', 'kpu-attest'] as $key) {
                $field = $fields->get($key);
                if ($field) {
                    $otherRules = $field->show_if ?? [];
                    unset($otherRules['kpu-status'], $otherRules['medisch.kpu-status']);
                    $field->update(['show_if' => $visibility($field, ['kpu-status' => $positive]) + $otherRules]);
                }
            }
            $ordered = $section->fields()->whereNotIn('id', $orderedIds)->orderBy('id')->pluck('id')->all();
            array_splice($ordered, array_search($status->id, $ordered, true) + 1, 0, $orderedIds);
            foreach ($ordered as $order => $id) $section->fields()->whereKey($id)->update(['order' => $order]);
        });
    }

    public function down(): void
    {
        // Preserve submitted answers and subsequent admin edits.
    }
};
