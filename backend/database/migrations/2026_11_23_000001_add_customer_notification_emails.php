<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_bookings', function (Blueprint $table) {
            $table->string('confirmation_email_status')->nullable()->index();
            $table->timestampTz('confirmation_email_claimed_at')->nullable();
            $table->timestampTz('confirmation_email_sent_at')->nullable();
            $table->text('confirmation_email_error')->nullable();
        });
        Schema::table('protocols', function (Blueprint $table) {
            $table->string('published_email_status')->nullable()->index();
            $table->timestampTz('published_email_claimed_at')->nullable();
            $table->timestampTz('published_email_sent_at')->nullable();
            $table->text('published_email_error')->nullable();
        });
        // Existing submissions and published plans get no retrospective emails.
    }

    public function down(): void
    {
        Schema::table('intake_bookings', fn (Blueprint $table) => $table->dropColumn([
            'confirmation_email_status', 'confirmation_email_claimed_at', 'confirmation_email_sent_at', 'confirmation_email_error',
        ]));
        Schema::table('protocols', fn (Blueprint $table) => $table->dropColumn([
            'published_email_status', 'published_email_claimed_at', 'published_email_sent_at', 'published_email_error',
        ]));
    }
};
