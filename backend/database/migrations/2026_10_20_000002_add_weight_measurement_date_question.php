<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $sections = DB::table('intake_sections')->whereIn('questionnaire_id',
                DB::table('intake_questionnaires')->where('slug', 'protocol-intake')->select('id'))->get();
            foreach ($sections as $section) {
                $method = DB::table('intake_fields')->where('section_id', $section->id)->where('key', 'gewicht-methode')->first();
                if (! $method || ($method->answer_section_key ?? $section->key) !== 'paard') {
                    continue;
                }
                // Preserve a definition already added or moved by an administrator.
                if (DB::table('intake_fields')->whereIn('section_id', $sections->where('questionnaire_id', $section->questionnaire_id)->pluck('id'))
                    ->where('key', 'gewicht-methode-wanneer')->exists()) {
                    continue;
                }
                DB::table('intake_fields')->where('section_id', $section->id)->where('order', '>', $method->order)->increment('order');
                DB::table('intake_fields')->insert([
                    'id' => (string) Str::uuid(), 'section_id' => $section->id, 'key' => 'gewicht-methode-wanneer',
                    'order' => $method->order + 1, 'answer_section_key' => 'paard',
                    'label' => 'Wanneer is het gewicht bepaald?', 'type' => 'text',
                    'required' => true, 'optional' => false, 'active' => $method->active,
                    'placeholder' => 'Bijv. mei 2026',
                    'show_if' => json_encode(array_merge(json_decode($method->show_if ?? '{}', true), [
                        'paard.gewicht-methode' => ['gewogen op weegbrug', 'gemeten met gewichts-lint', 'rapport dierenarts'],
                    ])),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        // Existing paard.gewicht-methode-wanneer answers are reused verbatim.
    }

    public function down(): void
    {
        // Keep saved dates and any later questionnaire edits available.
    }
};
