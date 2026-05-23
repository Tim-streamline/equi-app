<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_bookings', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('horse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('therapist_id')->constrained()->restrictOnDelete();
            $table->timestamp('scheduled_at');
            $table->string('slot_label')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->string('status', 16)->default('pending'); // pending | confirmed | done | cancelled
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['therapist_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_bookings');
    }
};
