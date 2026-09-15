<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->string('timezone')->default('Europe/Amsterdam');
        });
        Schema::create('protocol_phase_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('protocol_phase_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->date('available_on');
            $table->string('ticket_id')->nullable();
            $table->timestamp('sent_at');
            $table->unique(['protocol_phase_id', 'user_id', 'available_on'], 'phase_reminder_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_phase_reminders');
        Schema::table('notification_preferences', fn (Blueprint $table) => $table->dropColumn('timezone'));
    }
};
