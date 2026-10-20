<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('intake_fields')->where('key', 'vacc-laatst')
            ->whereIn('section_id', DB::table('intake_sections')->where('key', 'medisch')
                ->whereIn('questionnaire_id', DB::table('intake_questionnaires')->where('slug', 'protocol-intake')->select('id'))->select('id'))
            ->update([
                'type' => 'text',
                'hint' => 'Maand en jaartal is voldoende indien je de exacte datum niet weet. Indien niet bekend, vul dan "onbekend" in.',
                'updated_at' => now(),
            ]);
        // Existing answers remain stored under medisch.vacc-laatst as strings.
    }

    public function down(): void
    {
        // Retain existing answers and any later admin edits.
    }
};
