<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('intake_fields')->where('key', 'hoeven-anders')->where('label', 'Anders, namelijk')
            ->whereIn('section_id', DB::table('intake_sections')->where('key', 'medisch')
                ->whereIn('questionnaire_id', DB::table('intake_questionnaires')->where('slug', 'protocol-intake')->select('id'))->select('id'))
            ->update([
                'label' => 'Licht je antwoord toe',
                'show_if' => json_encode(['hoeven-bijz' => 'anders'], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Preserve answers and any later admin edits.
    }
};
