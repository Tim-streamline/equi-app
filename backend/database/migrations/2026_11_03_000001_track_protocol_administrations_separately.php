<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocol_supplement_intakes', function (Blueprint $table) {
            $table->unsignedSmallInteger('administration')->default(1);
            $table->dropUnique('protocol_supplement_intakes_supplement_date_unique');
            $table->unique(['protocol_phase_supplement_id', 'date', 'administration'], 'protocol_intake_administration_unique');
        });
    }

    public function down(): void
    {
        // Reinstating the old constraint fails safely if multiple gifts have been recorded.
        Schema::table('protocol_supplement_intakes', function (Blueprint $table) {
            $table->dropUnique('protocol_intake_administration_unique');
            $table->unique(['protocol_phase_supplement_id', 'date'], 'protocol_supplement_intakes_supplement_date_unique');
            $table->dropColumn('administration');
        });
    }
};
