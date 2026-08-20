<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_phase_supplements', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('protocol_phase_id')->constrained('protocol_phases')->cascadeOnDelete();
            $table->foreignUuid('supplement_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['protocol_phase_id', 'supplement_id'],
                'protocol_phase_supplements_phase_supplement_unique',
            );
        });

        DB::table('protocol_phases')
            ->join('supplements', function ($join) {
                $join->on('supplements.protocol_type_phase_id', '=', 'protocol_phases.protocol_type_phase_id')
                    ->where('supplements.add_by_default', true);
            })
            ->select([
                'protocol_phases.id as protocol_phase_id',
                'supplements.id as supplement_id',
            ])
            ->orderBy('protocol_phases.id')
            ->each(function (object $selection): void {
                DB::table('protocol_phase_supplements')->insert([
                    'id' => (string) Str::uuid(),
                    'protocol_phase_id' => $selection->protocol_phase_id,
                    'supplement_id' => $selection->supplement_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_phase_supplements');
    }
};
