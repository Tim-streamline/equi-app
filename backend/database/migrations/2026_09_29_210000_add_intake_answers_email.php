<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_bookings', function (Blueprint $table) {
            $table->string('answers_email_status')->nullable()->index();
            $table->string('answers_email_to')->nullable();
            $table->timestampTz('answers_email_claimed_at')->nullable();
            $table->timestampTz('answers_email_sent_at')->nullable();
            $table->text('answers_email_error')->nullable();
        });
        // Only replace the old stock hint. Preserve customized questions and answers.
        DB::table('intake_fields')->where('key', 'email')
            ->whereIn('section_id', DB::table('intake_sections')->select('id')->where('key', 'contact')
                ->whereIn('questionnaire_id', DB::table('intake_questionnaires')->select('id')->where('slug', 'protocol-intake')))
            ->where('hint', 'Op dit adres stuur ik je een kopie van je antwoorden en een kopie van je protocol.')
            ->update(['hint' => 'Na het afronden van de intake ontvang je op dit adres een kopie van je antwoorden.', 'updated_at' => now()]);
        // Existing submissions deliberately do not receive retrospective emails.
    }

    public function down(): void
    {
        Schema::table('intake_bookings', fn (Blueprint $table) => $table->dropColumn([
            'answers_email_status', 'answers_email_to', 'answers_email_claimed_at', 'answers_email_sent_at', 'answers_email_error',
        ]));
    }
};
