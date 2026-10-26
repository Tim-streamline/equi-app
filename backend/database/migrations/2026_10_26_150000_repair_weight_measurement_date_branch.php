<?php

use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // Reuse the original answer key, creating its definition only if missing.
            (require database_path('migrations/2026_10_20_000002_add_weight_measurement_date_question.php'))->up();
            $questionnaire = IntakeQuestionnaire::where('slug', 'protocol-intake')->with('sections.fields')->first();
            $fields = $questionnaire?->sections->flatMap(fn ($section) => $section->fields);
            $method = $fields?->firstWhere('key', 'gewicht-methode');
            $date = $fields?->firstWhere('key', 'gewicht-methode-wanneer');
            if (! $method || ! $date) return;

            $legacy = ['gewogen op weegbrug', 'gemeten met gewichts-lint', 'rapport dierenarts'];
            $normalize = fn ($value) => str_replace('-', '', mb_strtolower(trim($value)));
            $measured = array_map($normalize, $legacy);
            $options = array_values(array_filter($method->options ?? [], fn ($option) => in_array($normalize($option), $measured, true)));
            // CMS labels may use capitals or omit the hyphen in "gewichts-lint".
            $conditions = $date->show_if ?? [];
            unset($conditions['gewicht-methode']);
            $conditions['paard.gewicht-methode'] = array_values(array_unique([...$legacy, ...$options]));
            $date->update([
                'label' => 'Wanneer was dat?', 'type' => 'month_year',
                'answer_section_key' => 'paard', 'active' => true, 'required' => true, 'optional' => false,
                'hint' => 'Vul de maand en het jaar in. Een schatting is goed.', 'placeholder' => null,
                'show_if' => $conditions,
                'date_config' => array_replace(['allow_year_only' => true], $date->date_config ?? [], ['mode' => 'single', 'allow_future' => false]),
            ]);
        });
    }

    public function down(): void
    {
        // Preserve saved dates and subsequent administrator changes.
    }
};
