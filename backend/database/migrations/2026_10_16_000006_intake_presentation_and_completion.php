<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_sections', fn (Blueprint $table) => $table->json('presentation')->nullable());
        Schema::table('intake_bookings', fn (Blueprint $table) => $table->timestampTz('completed_at')->nullable());
        DB::table('intake_bookings')->whereNotNull('submitted_at')->update(['completed_at' => DB::raw('submitted_at')]);
        $snapshot = json_decode(file_get_contents(__DIR__.'/support/intake-presentation-opt139-143.json'), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($snapshot) {
            $sections = DB::table('intake_sections')->whereIn('questionnaire_id', DB::table('intake_questionnaires')->where('slug', 'protocol-intake')->select('id'))->get();
            foreach ($sections as $section) {
                if (! isset($snapshot['sections'][$section->key])) {
                    continue;
                }
                $data = $snapshot['sections'][$section->key];
                $update = ['title' => $data['title'], 'subtitle' => $data['sub'], 'updated_at' => now()];
                if ($section->key === 'intake-fotos') {
                    $update += ['intro' => $data['intro'], 'presentation' => json_encode($data['presentation'], JSON_THROW_ON_ERROR)];
                    foreach ($snapshot['fields'] as $key => $copy) {
                        $field = DB::table('intake_fields')->where('section_id', $section->id)->where('key', $key)->first();
                        if (! $field) {
                            continue;
                        }
                        $config = json_decode($field->photo_config ?? '{}', true) ?? [];
                        $config['instruction_label'] = $copy['instruction_label'];
                        $config['instructions'] = $copy['instructions'];
                        DB::table('intake_fields')->where('id', $field->id)->update(['hint' => $copy['hint'], 'photo_config' => json_encode($config, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                    }
                }
                DB::table('intake_sections')->where('id', $section->id)->update($update);
            }
        });
    }

    public function down(): void
    {
        // Keep submitted intake locks and admin-edited content. Restore the deployment backup for rollback.
    }
};
