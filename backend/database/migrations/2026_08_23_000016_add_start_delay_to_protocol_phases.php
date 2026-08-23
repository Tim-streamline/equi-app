<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocol_template_phases', function (Blueprint $table) {
            $table->unsignedSmallInteger('start_after_previous_phase_weeks')->nullable();
        });

        Schema::table('protocol_phases', function (Blueprint $table) {
            $table->unsignedSmallInteger('start_after_previous_phase_weeks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('protocol_phases', function (Blueprint $table) {
            $table->dropColumn('start_after_previous_phase_weeks');
        });

        Schema::table('protocol_template_phases', function (Blueprint $table) {
            $table->dropColumn('start_after_previous_phase_weeks');
        });
    }
};
