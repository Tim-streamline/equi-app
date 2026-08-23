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
        Schema::table('protocols', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable();
        });

        Schema::create('protocol_phase_weeks', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('protocol_phase_id')->constrained('protocol_phases')->cascadeOnDelete();
            $table->foreignUuid('protocol_type_phase_week_id')->nullable()->constrained('protocol_type_phase_weeks')->nullOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedInteger('protocol_week_number');
            $table->timestamps();

            $table->unique(['protocol_phase_id', 'number'], 'protocol_phase_weeks_phase_number_unique');
            $table->index('protocol_week_number');
        });

        Schema::table('protocol_phase_supplements', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('supplement_type')->nullable();
            $table->string('dosage')->nullable();
            $table->unsignedInteger('aantal_per_week')->nullable();
            $table->text('instructions')->nullable();
        });

        Schema::table('protocol_phase_supplements', function (Blueprint $table) {
            $table->dropForeign(['supplement_id']);
        });
        Schema::table('protocol_phase_supplements', function (Blueprint $table) {
            $table->uuid('supplement_id')->nullable()->change();
            $table->foreign('supplement_id')->references('id')->on('supplements')->nullOnDelete();
        });

        Schema::create('protocol_phase_supplement_weeks', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('protocol_phase_supplement_id')->constrained('protocol_phase_supplements')->cascadeOnDelete();
            $table->foreignUuid('protocol_phase_week_id')->constrained('protocol_phase_weeks')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['protocol_phase_supplement_id', 'protocol_phase_week_id'],
                'protocol_phase_supplement_weeks_selection_unique',
            );
        });

        $this->backfillProtocolWeeks();
        $this->backfillProtocolSupplementSnapshotsAndWeeks();
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_phase_supplement_weeks');

        Schema::table('protocol_phase_supplements', function (Blueprint $table) {
            $table->dropForeign(['supplement_id']);
            $table->dropColumn(['name', 'description', 'supplement_type', 'dosage', 'aantal_per_week', 'instructions']);
        });
        DB::table('protocol_phase_supplements')->whereNull('supplement_id')->delete();
        Schema::table('protocol_phase_supplements', function (Blueprint $table) {
            $table->uuid('supplement_id')->nullable(false)->change();
            $table->foreign('supplement_id')->references('id')->on('supplements')->cascadeOnDelete();
        });

        Schema::dropIfExists('protocol_phase_weeks');

        Schema::table('protocols', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }

    private function backfillProtocolWeeks(): void
    {
        DB::table('protocols')->orderBy('id')->each(function (object $protocol): void {
            $phaseRows = DB::table('protocol_phases')
                ->where('protocol_id', $protocol->id)
                ->orderBy('order')
                ->get();
            $nextProtocolWeek = 1;

            foreach ($phaseRows as $phaseRow) {
                $definitionWeeks = DB::table('protocol_type_phase_weeks')
                    ->where('protocol_type_phase_id', $phaseRow->protocol_type_phase_id)
                    ->orderBy('number')
                    ->get();
                $weekStart = $phaseRow->week_start ?? $nextProtocolWeek;
                $weekCount = $phaseRow->week_start !== null && $phaseRow->week_end !== null
                    ? max(0, ((int) $phaseRow->week_end - (int) $phaseRow->week_start) + 1)
                    : $definitionWeeks->count();

                for ($number = 1; $number <= $weekCount; $number++) {
                    DB::table('protocol_phase_weeks')->insert([
                        'id' => (string) Str::uuid(),
                        'protocol_phase_id' => $phaseRow->id,
                        'protocol_type_phase_week_id' => $definitionWeeks->firstWhere('number', $number)?->id,
                        'number' => $number,
                        'protocol_week_number' => ((int) $weekStart) + $number - 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $nextProtocolWeek = ((int) $weekStart) + $weekCount;
            }

            DB::table('protocols')->where('id', $protocol->id)->update([
                'published_at' => $protocol->created_at,
            ]);
        });
    }

    private function backfillProtocolSupplementSnapshotsAndWeeks(): void
    {
        DB::table('protocol_phase_supplements')->orderBy('id')->each(function (object $selection): void {
            $supplement = DB::table('supplements')->where('id', $selection->supplement_id)->first();
            if (! $supplement) {
                return;
            }

            DB::table('protocol_phase_supplements')->where('id', $selection->id)->update([
                'name' => $supplement->name,
                'description' => $supplement->description,
                'supplement_type' => $supplement->supplement_type,
                'aantal_per_week' => $supplement->min_aantal_per_week,
            ]);

            $weekNumbers = DB::table('supplement_weeks')
                ->join('protocol_type_phase_weeks', 'protocol_type_phase_weeks.id', '=', 'supplement_weeks.protocol_type_phase_week_id')
                ->where('supplement_weeks.supplement_id', $supplement->id)
                ->pluck('protocol_type_phase_weeks.number');
            $protocolPhaseWeeks = DB::table('protocol_phase_weeks')
                ->where('protocol_phase_id', $selection->protocol_phase_id)
                ->whereIn('number', $weekNumbers)
                ->get();

            foreach ($protocolPhaseWeeks as $protocolPhaseWeek) {
                DB::table('protocol_phase_supplement_weeks')->insert([
                    'id' => (string) Str::uuid(),
                    'protocol_phase_supplement_id' => $selection->id,
                    'protocol_phase_week_id' => $protocolPhaseWeek->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
};
