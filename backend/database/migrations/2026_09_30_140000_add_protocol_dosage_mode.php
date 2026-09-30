<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocol_phase_supplements', function (Blueprint $table) {
            // Historical doses have unknown provenance: preserve until explicitly reset.
            $table->string('dosage_mode')->default('manual');
        });
    }

    public function down(): void
    {
        Schema::table('protocol_phase_supplements', fn (Blueprint $table) => $table->dropColumn('dosage_mode'));
    }
};
