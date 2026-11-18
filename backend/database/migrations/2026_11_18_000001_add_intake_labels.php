<?php

use App\Support\IntakeLabels;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_fields', fn (Blueprint $table) => $table->json('label_config')->nullable());
        // Read the actual admin database, never the bundled questionnaire JSON.
        DB::table('intake_fields')->orderBy('id')->chunkById(100, function ($fields) {
            foreach ($fields as $field) {
                $data = (array) $field;
                foreach (['options', 'repeater_sub'] as $column) $data[$column] = json_decode($data[$column] ?? 'null', true);
                $section = DB::table('intake_sections')->where('id', $field->section_id)->value('key');
                $config = app(IntakeLabels::class)->complete($data, $section.'_'.$field->key, null);
                DB::table('intake_fields')->where('id', $field->id)->update(['label_config' => json_encode($config)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('intake_fields', fn (Blueprint $table) => $table->dropColumn('label_config'));
    }
};
