<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_supplement_intakes', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('protocol_phase_supplement_id')
                ->constrained('protocol_phase_supplements')
                ->cascadeOnDelete();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('dosage')->nullable();
            $table->boolean('done')->default(false);
            $table->timestamp('taken_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['protocol_phase_supplement_id', 'date'],
                'protocol_supplement_intakes_supplement_date_unique',
            );
            $table->index(['horse_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_supplement_intakes');
    }
};
