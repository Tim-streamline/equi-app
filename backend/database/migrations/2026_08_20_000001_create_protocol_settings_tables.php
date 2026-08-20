<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_types', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('protocol_type_phases', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('protocol_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('order');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('required')->default(false);
            $table->timestamps();

            $table->index(['protocol_type_id', 'order']);
        });

        Schema::create('protocol_type_phase_weeks', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('protocol_type_phase_id')->constrained('protocol_type_phases')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->timestamps();

            $table->unique(['protocol_type_phase_id', 'number'], 'phase_weeks_phase_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_type_phase_weeks');
        Schema::dropIfExists('protocol_type_phases');
        Schema::dropIfExists('protocol_types');
    }
};
