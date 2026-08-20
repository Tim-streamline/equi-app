<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplement_weeks', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('supplement_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('protocol_type_phase_week_id')->constrained('protocol_type_phase_weeks')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['supplement_id', 'protocol_type_phase_week_id'],
                'supplement_weeks_supplement_week_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplement_weeks');
    }
};
