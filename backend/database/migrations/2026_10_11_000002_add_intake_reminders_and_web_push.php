<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_bookings', function (Blueprint $table) {
            $table->timestampTz('last_activity_at')->nullable()->index();
            $table->string('last_section', 64)->nullable();
            $table->string('last_field', 100)->nullable();
            $table->timestampTz('last_reminder_at')->nullable();
        });
        Schema::create('web_push_subscriptions', function (Blueprint $table) {
            $table->string('endpoint_hash', 64)->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->text('subscription');
            $table->timestamps();
        });
        Schema::create('intake_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('booking_id')->constrained('intake_bookings')->cascadeOnDelete();
            $table->string('cycle', 64);
            $table->string('destination', 64);
            $table->timestampTz('sent_at');
            $table->unique(['booking_id', 'cycle', 'destination']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_reminder_deliveries');
        Schema::dropIfExists('web_push_subscriptions');
        Schema::table('intake_bookings', fn (Blueprint $table) => $table->dropColumn(['last_activity_at', 'last_section', 'last_field', 'last_reminder_at']));
    }
};
