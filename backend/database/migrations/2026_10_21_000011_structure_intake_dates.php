<?php

use App\Models\IntakeAnswer;
use App\Models\IntakeQuestionnaire;
use App\Support\IntakeDates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('intake_fields', 'date_config')) Schema::table('intake_fields', fn (Blueprint $table) => $table->json('date_config')->nullable());
        DB::transaction(function (): void {
            $questionnaire = IntakeQuestionnaire::where('slug', 'protocol-intake')->first();
            if (! $questionnaire) return;
            $plan = json_decode(file_get_contents(database_path('data/month-year-2026-10-21.json')), true, flags: JSON_THROW_ON_ERROR);
            $columns = ['dateConfig' => 'date_config', 'sub' => 'repeater_sub'];
            $convert = [];
            foreach ($plan['patches'] as $change) {
                $section = $questionnaire->sections()->where('key', $change['section'])->first();
                $field = $section?->fields()->where('key', $change['id'])->first();
                if (! $field) continue;
                $patch = [];
                foreach ($change['patch'] as $key => $value) $patch[$columns[$key] ?? $key] = $value;
                // Preserve locally edited non-date repeater subfields and their order.
                if (isset($patch['repeater_sub'])) {
                    $subPatches = collect($patch['repeater_sub'])->where('type', 'month_year')->keyBy('id');
                    $patch['repeater_sub'] = array_map(fn ($sub) => isset($subPatches[$sub['id']])
                        ? array_replace($sub, array_intersect_key($subPatches[$sub['id']], array_flip(['type', 'hint', 'dateConfig']))) : $sub, $field->repeater_sub ?? []);
                }
                $field->update($patch);
                $convert[] = [$change['section'], $field->fresh()];
            }
            foreach ($plan['added'] as $addition) {
                $section = $questionnaire->sections()->where('key', $addition['section'])->first();
                $before = $section?->fields()->where('key', $addition['before'])->first();
                if (! $before || $section->fields()->where('key', $addition['field']['id'])->exists()) continue;
                $f = $addition['field'];
                $section->fields()->where('order', '>=', $before->order)->increment('order');
                $section->fields()->create(['key' => $f['id'], 'order' => $before->order, 'active' => true,
                    'type' => 'month_year', 'label' => $f['label'], 'hint' => $f['hint'], 'date_config' => $f['dateConfig'],
                    'show_if' => $before->show_if, 'required' => $before->required, 'optional' => $before->optional]);
            }
            foreach ($convert as [$sectionKey, $field]) {
                IntakeAnswer::where('section_id', $field->answer_section_key ?? $sectionKey)->where('field_id', $field->key)
                    ->chunkById(200, function ($answers) use ($field): void {
                        foreach ($answers as $answer) {
                            $old = json_decode($answer->value ?? 'null', true);
                            $new = app(IntakeDates::class)->forField($field, $old);
                            if ($new !== $old) {
                                // Definition migration only: no activity timestamps or safety events change.
                                DB::table('intake_answers')->where('id', $answer->id)->update(['value' => json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                            }
                        }
                    });
            }
        });
    }

    public function down(): void
    {
        // Structured answers and the original legacy text must remain recoverable.
    }
};
