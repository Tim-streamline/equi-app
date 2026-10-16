<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('intake_fields', 'answer_section_key')) {
            Schema::table('intake_fields', function (Blueprint $table) {
                // Presentation can move without changing saved answers, attachments,
                // or writes still queued on an offline device.
                $table->string('answer_section_key', 64)->nullable();
            });
        }

        foreach (DB::table('intake_questionnaires')->where('slug', 'protocol-intake')->get() as $questionnaire) {
            $sections = DB::table('intake_sections')->where('questionnaire_id', $questionnaire->id)->orderBy('order')->get();
            $photos = $sections->firstWhere('key', 'intake-fotos');
            $id = $photos?->id ?? (string) Str::uuid();
            if (! $photos) {
                DB::table('intake_sections')->where('questionnaire_id', $questionnaire->id)->increment('order');
                DB::table('intake_sections')->insert([
                    'id' => $id, 'questionnaire_id' => $questionnaire->id, 'key' => 'intake-fotos',
                    'order' => 0, 'title' => 'Intake fotos', 'intro' => 'Voeg hier de foto’s voor je intake toe.',
                    'minutes' => 10, 'icon' => 'camera', 'subtitle' => 'Foto’s van je paard en de leefomgeving',
                    'active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $order = (int) DB::table('intake_fields')->where('section_id', $id)->max('order') + 1;
            foreach ($sections->where('id', '!=', $id) as $section) {
                $fields = DB::table('intake_fields')->where('section_id', $section->id)
                    ->where(fn ($q) => $q->where('type', 'photo')->orWhere('key', 'sec-hoef-upload'))->orderBy('order')->get();
                foreach ($fields as $field) {
                    // Never overwrite a custom field with the same key in the destination.
                    if (DB::table('intake_fields')->where('section_id', $id)->where('key', $field->key)->exists()) {
                        throw new RuntimeException('Duplicate intake photo field key: '.$field->key);
                    }
                    $conditions = json_decode($field->show_if ?? 'null', true);
                    if ($conditions) {
                        $conditions = collect($conditions)->mapWithKeys(fn ($value, $key) => [
                            str_contains($key, '.') ? $key : $section->key.'.'.$key => $value,
                        ])->all();
                    }
                    DB::table('intake_fields')->where('id', $field->id)->update([
                        'section_id' => $id, 'answer_section_key' => $section->key, 'order' => $order++,
                        'show_if' => $conditions ? json_encode($conditions, JSON_THROW_ON_ERROR) : null,
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Keep the section and its answer aliases: dropping them would orphan
        // existing uploads and answers. Restore a database backup for a full rollback.
    }
};
