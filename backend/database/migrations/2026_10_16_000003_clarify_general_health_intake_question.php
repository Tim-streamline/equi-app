<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the existing field identity, answer namespace and saved responses.
        // Only this question's wording and compact input presentation change.
        DB::table('intake_fields')->where('key', 'gezondheid-eigen-woorden')
            ->whereIn('section_id', DB::table('intake_sections')->where('key', 'paard')
                ->whereIn('questionnaire_id', DB::table('intake_questionnaires')->where('slug', 'protocol-intake')->select('id'))->select('id'))
            ->update([
                'label' => 'Hoe gaat het volgens jou op dit moment in het algemeen met je paard?',
                'hint' => 'Geef hier kort je algemene indruk van hoe je paard zich op dit moment voelt en functioneert. Denk bijvoorbeeld aan energie, uitstraling, comfort en algemeen welzijn. Specifieke klachten en medische informatie vragen we verderop uitgebreider uit.',
                'type' => 'textarea',
                'tall' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Retain editable copy and existing answers; restore a backup for rollback.
    }
};
