<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('intake_fields', 'photo_config')) {
            Schema::table('intake_fields', fn (Blueprint $table) => $table->json('photo_config')->nullable());
        }
        $definition = json_decode(file_get_contents(__DIR__.'/support/intake-photos-opt132.json'), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($definition): void {
            $sections = DB::table('intake_sections')->where('key', 'intake-fotos')
                ->whereIn('questionnaire_id', DB::table('intake_questionnaires')->where('slug', 'protocol-intake')->select('id'))->get();
            foreach ($sections as $section) {
                // Only the first photo section is touched. Keep identities, aliases,
                // optional/required settings, custom fields, answers and files intact.
                DB::table('intake_sections')->where('id', $section->id)->update([
                    'intro' => $definition['intro'], 'subtitle' => $definition['sub'], 'updated_at' => now(),
                ]);
                foreach ($definition['fields'] as $order => $field) {
                    DB::table('intake_fields')->where('section_id', $section->id)->where('key', $field['id'])->update([
                        'label' => $field['label'], 'hint' => $field['hint'] ?? null, 'order' => $order,
                        'show_if' => null, 'photo_config' => isset($field['photoConfig']) ? json_encode($field['photoConfig'], JSON_THROW_ON_ERROR) : null,
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Keep configuration and existing photo metadata; restore a backup for rollback.
    }
};
