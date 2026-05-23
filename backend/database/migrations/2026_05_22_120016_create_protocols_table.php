<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocols', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('therapist_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('subtitle_analyse')->nullable();
            $table->string('subtitle_protocol')->nullable();
            $table->string('subtitle_calendar')->nullable();
            $table->unsignedSmallInteger('total_weeks')->nullable();
            $table->unsignedSmallInteger('current_week')->nullable();
            $table->date('started_at')->nullable();
            $table->string('status', 16)->default('active'); // active | paused | completed
            $table->timestamps();
            $table->index(['horse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocols');
    }
};
