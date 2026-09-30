<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_bookings', function (Blueprint $table) {
            $table->string('submission_email_status')->nullable()->index();
            $table->timestampTz('submission_email_claimed_at')->nullable();
            $table->timestampTz('submission_email_sent_at')->nullable();
            $table->text('submission_email_error')->nullable();
        });
        // Existing submissions keep their dates and review status; no retrospective emails.
    }

    public function down(): void
    {
        Schema::table('intake_bookings', fn (Blueprint $table) => $table->dropColumn([
            'submission_email_status', 'submission_email_claimed_at', 'submission_email_sent_at', 'submission_email_error',
        ]));
    }
};
