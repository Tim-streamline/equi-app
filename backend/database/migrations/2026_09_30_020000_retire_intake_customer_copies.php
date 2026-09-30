<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Retain delivery history and customer answers, but stop all unclaimed copies.
        DB::table('intake_bookings')->where('answers_email_status', 'pending')
            ->update(['answers_email_status' => 'cancelled', 'updated_at' => now()]);
        DB::table('intake_fields')->where('key', 'email')
            ->whereIn('section_id', DB::table('intake_sections')->select('id')->where('key', 'contact')
                ->whereIn('questionnaire_id', DB::table('intake_questionnaires')->select('id')->where('slug', 'protocol-intake')))
            ->whereIn('hint', [
                'Na het afronden van de intake ontvang je op dit adres een kopie van je antwoorden.',
                'Op dit adres stuur ik je een kopie van je antwoorden en een kopie van je protocol.',
            ])->update(['hint' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Do not re-enable customer emails on rollback.
    }
};
