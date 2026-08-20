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
        Schema::table('protocol_phases', function (Blueprint $table) {
            $table->foreignUuid('protocol_type_phase_id')
                ->nullable()
                ->constrained('protocol_type_phases')
                ->restrictOnDelete();
        });

        $protocolTypeIds = DB::table('protocols')
            ->distinct()
            ->pluck('protocol_type_id');

        foreach ($protocolTypeIds as $protocolTypeId) {
            $protocolIds = DB::table('protocols')
                ->where('protocol_type_id', $protocolTypeId)
                ->pluck('id');
            $maximumPhaseCount = (int) DB::table('protocol_phases')
                ->whereIn('protocol_id', $protocolIds)
                ->selectRaw('count(*) as phase_count')
                ->groupBy('protocol_id')
                ->pluck('phase_count')
                ->max();
            $definitions = DB::table('protocol_type_phases')
                ->where('protocol_type_id', $protocolTypeId)
                ->orderBy('order')
                ->orderBy('created_at')
                ->get();
            $nextOrder = ((int) $definitions->max('order')) + 1;

            while ($definitions->count() < $maximumPhaseCount) {
                $phaseNumber = $definitions->count() + 1;
                $id = (string) Str::uuid();
                DB::table('protocol_type_phases')->insert([
                    'id' => $id,
                    'protocol_type_id' => $protocolTypeId,
                    'order' => $nextOrder++,
                    'name' => "Fase {$phaseNumber}",
                    'description' => null,
                    'required' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $definitions->push((object) ['id' => $id]);
            }

            foreach ($protocolIds as $protocolId) {
                DB::table('protocol_phases')
                    ->where('protocol_id', $protocolId)
                    ->orderBy('order')
                    ->orderBy('created_at')
                    ->get(['id'])
                    ->values()
                    ->each(function (object $phase, int $index) use ($definitions): void {
                        DB::table('protocol_phases')
                            ->where('id', $phase->id)
                            ->update(['protocol_type_phase_id' => $definitions[$index]->id]);
                    });
            }
        }

        DB::statement('ALTER TABLE protocol_phases ALTER COLUMN protocol_type_phase_id SET NOT NULL');

        Schema::table('protocol_phases', function (Blueprint $table) {
            $table->unique(
                ['protocol_id', 'protocol_type_phase_id'],
                'protocol_phases_protocol_definition_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('protocol_phases', function (Blueprint $table) {
            $table->dropUnique('protocol_phases_protocol_definition_unique');
            $table->dropConstrainedForeignId('protocol_type_phase_id');
        });
    }
};
