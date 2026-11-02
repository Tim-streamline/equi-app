<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_fields', function (Blueprint $table) {
            $table->jsonb('option_effects')->nullable();
        });

        // Preserve the current selection behavior using each questionnaire's own settings.
        // Only definitions change; saved customer answers remain untouched.
        foreach (DB::table('intake_questionnaires')->get(['id', 'none_options']) as $questionnaire) {
            $none = array_map(fn ($value) => mb_strtolower(trim($value)), json_decode($questionnaire->none_options ?? '[]', true) ?? []);
            $sectionIds = DB::table('intake_sections')->where('questionnaire_id', $questionnaire->id)->pluck('id');
            foreach (DB::table('intake_fields')->whereIn('section_id', $sectionIds)->where('type', 'multi')->get(['id', 'options']) as $field) {
                $effects = [];
                foreach (json_decode($field->options ?? '[]', true) ?? [] as $option) {
                    if (in_array(mb_strtolower(trim($option)), $none, true)) {
                        $effects[$option] = ['exclusive_selection' => true];
                    }
                }
                if ($effects) {
                    DB::table('intake_fields')->where('id', $field->id)->update(['option_effects' => json_encode($effects, JSON_THROW_ON_ERROR)]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('intake_fields', function (Blueprint $table) {
            $table->dropColumn('option_effects');
        });
    }
};
