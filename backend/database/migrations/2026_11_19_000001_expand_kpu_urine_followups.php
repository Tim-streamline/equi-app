<?php

use App\Models\IntakeQuestionnaire;
use App\Support\IntakeDates;
use App\Support\IntakeLabels;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $section = IntakeQuestionnaire::where('slug', 'protocol-intake')->first()?->sections()->where('key', 'medisch')->first();
            $status = $section?->fields()->where('key', 'kpu-status')->first();
            if (! $status) return;
            $plan = json_decode(file_get_contents(database_path('data/kpu-followups-2026-11-19.json')), true, flags: JSON_THROW_ON_ERROR);
            // Include the current admin wording; keep all existing options and answer values.
            $positive = array_values(array_unique(array_merge($plan['positive'], array_values(array_filter($status->options ?? [],
                fn ($option) => preg_match('/^(ja\b|aangetoond).*urine/iu', $option))))));
            $keys = [];
            foreach ($plan['fields'] as $definition) {
                $key = $definition['id'];
                $keys[] = $key;
                $attributes = ['label' => $definition['label'], 'type' => $definition['type'],
                    'required' => true, 'optional' => false, 'active' => true,
                    'show_if' => ['kpu-status' => $positive] + ($definition['showIf'] ?? [])];
                foreach (['hint' => 'hint', 'placeholder' => 'placeholder', 'options' => 'options', 'dateConfig' => 'date_config'] as $from => $to) {
                    if (array_key_exists($from, $definition)) $attributes[$to] = $definition[$from];
                }
                $field = $section->fields()->updateOrCreate(['key' => $key], $attributes + ['order' => 0]);
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
            $section->fields()->where('key', 'kpu-onderzoek-uitslag')->update(['active' => false]);
            foreach (['kpu-uitslag-beschikbaar', 'kpu-attest'] as $key) {
                $field = $section->fields()->where('key', $key)->first();
                if ($field) $field->update(['show_if' => array_replace($field->show_if ?? [], ['kpu-status' => $positive])]);
            }
            $ordered = $section->fields()->whereNotIn('key', $keys)->orderBy('order')->pluck('key')->all();
            array_splice($ordered, array_search('kpu-status', $ordered, true) + 1, 0, $keys);
            foreach ($ordered as $order => $key) $section->fields()->where('key', $key)->update(['order' => $order]);
        });
    }

    public function down(): void
    {
        // Preserve submitted answers and subsequent admin edits.
    }
};
