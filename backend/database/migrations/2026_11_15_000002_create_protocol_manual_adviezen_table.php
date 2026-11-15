<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Advice a therapist writes for one protocol, outside the measure library, optionally limited to some of its phases. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_manual_adviezen', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('protocol_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('title');
            $table->text('description');
            $table->string('management_category')->nullable();
            $table->string('action')->default('do');
            $table->string('applies_to')->default('protocol');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['protocol_id', 'category', 'sort_order']);
        });
        DB::statement("ALTER TABLE protocol_manual_adviezen ADD CONSTRAINT protocol_manual_adviezen_category_check CHECK (category IN ('voeding', 'management', 'beweging'))");
        DB::statement("ALTER TABLE protocol_manual_adviezen ADD CONSTRAINT protocol_manual_adviezen_management_category_check CHECK (management_category IS NULL OR management_category IN ('environment', 'care', 'monitoring'))");
        DB::statement("ALTER TABLE protocol_manual_adviezen ADD CONSTRAINT protocol_manual_adviezen_action_check CHECK (action IN ('do', 'avoid'))");
        DB::statement("ALTER TABLE protocol_manual_adviezen ADD CONSTRAINT protocol_manual_adviezen_applies_to_check CHECK (applies_to IN ('protocol', 'phases'))");

        Schema::create('protocol_manual_advies_phase', function (Blueprint $table) {
            $table->foreignUuid('protocol_manual_advies_id')->constrained('protocol_manual_adviezen')->cascadeOnDelete();
            $table->foreignUuid('protocol_phase_id')->constrained('protocol_phases')->cascadeOnDelete();
            $table->primary(['protocol_manual_advies_id', 'protocol_phase_id']);
            $table->index('protocol_phase_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_manual_advies_phase');
        Schema::dropIfExists('protocol_manual_adviezen');
    }
};
