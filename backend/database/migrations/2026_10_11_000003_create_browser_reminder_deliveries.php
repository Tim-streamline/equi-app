<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 64);
            $table->string('destination', 64);
            $table->timestampTz('sent_at');
            $table->unique(['user_id', 'event_key', 'destination']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_reminder_deliveries');
    }
};
