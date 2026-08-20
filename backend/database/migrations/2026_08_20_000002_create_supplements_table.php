<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplements', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('protocol_type_phase_id')->constrained('protocol_type_phases')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('supplement_type', ['kruid', 'mineraal', 'supplement']);
            $table->boolean('add_by_default')->default(false);
            $table->unsignedInteger('max_aantal_in_fase')->nullable();
            $table->unsignedInteger('min_aantal_per_week')->default(4);
            $table->unsignedInteger('rust_periode_in_weken')->default(2);
            $table->timestamps();

            $table->index(['protocol_type_phase_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplements');
    }
};
