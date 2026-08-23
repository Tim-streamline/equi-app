<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('protocol_phase_items');
    }

    public function down(): void
    {
        Schema::create('protocol_phase_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('phase_id')->constrained('protocol_phases')->cascadeOnDelete();
            $table->unsignedSmallInteger('order')->default(0);
            $table->string('label');
            $table->timestamps();
        });
    }
};
