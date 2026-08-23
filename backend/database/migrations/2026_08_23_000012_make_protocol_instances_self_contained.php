<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocols', function (Blueprint $table) {
            $table->string('protocol_type_name')->nullable()->after('protocol_type_id');
        });

        Schema::table('protocol_phases', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->boolean('required')->default(false)->after('description');
        });

        Schema::table('protocol_phase_supplements', function (Blueprint $table) {
            $table->string('dosis_type')->nullable()->after('supplement_type');
            $table->double('dosis')->nullable()->after('dosis_type');
            $table->string('unit')->nullable()->after('dosis');
            $table->boolean('add_by_default')->default(false)->after('unit');
            $table->unsignedInteger('max_aantal_in_fase')->nullable()->after('add_by_default');
            $table->unsignedInteger('min_aantal_per_week')->default(4)->after('max_aantal_in_fase');
            $table->unsignedInteger('rust_periode_in_weken')->default(2)->after('min_aantal_per_week');
        });

        $this->backfillInstanceData();

        if (Schema::hasColumn('protocols', 'template_version')) {
            Schema::table('protocols', function (Blueprint $table) {
                $table->dropColumn(['template_version', 'template_snapshot']);
            });
        }

        if (Schema::hasColumn('protocol_types', 'version')) {
            Schema::table('protocol_types', function (Blueprint $table) {
                $table->dropColumn('version');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('protocol_types', 'version')) {
            Schema::table('protocol_types', function (Blueprint $table) {
                $table->unsignedInteger('version')->default(1);
            });
        }

        if (! Schema::hasColumn('protocols', 'template_version')) {
            Schema::table('protocols', function (Blueprint $table) {
                $table->unsignedInteger('template_version')->default(1);
                $table->json('template_snapshot')->nullable();
            });
        }

        Schema::table('protocol_phase_supplements', function (Blueprint $table) {
            $table->dropColumn([
                'dosis_type',
                'dosis',
                'unit',
                'add_by_default',
                'max_aantal_in_fase',
                'min_aantal_per_week',
                'rust_periode_in_weken',
            ]);
        });

        Schema::table('protocol_phases', function (Blueprint $table) {
            $table->dropColumn(['description', 'required']);
        });

        Schema::table('protocols', function (Blueprint $table) {
            $table->dropColumn('protocol_type_name');
        });
    }

    private function backfillInstanceData(): void
    {
        DB::table('protocols')->orderBy('id')->each(function (object $protocol): void {
            DB::table('protocols')->where('id', $protocol->id)->update([
                'protocol_type_name' => DB::table('protocol_types')
                    ->where('id', $protocol->protocol_type_id)
                    ->value('name') ?? 'Protocol',
            ]);
        });

        DB::table('protocol_phases')->orderBy('id')->each(function (object $phase): void {
            $definition = DB::table('protocol_type_phases')->where('id', $phase->protocol_type_phase_id)->first();
            DB::table('protocol_phases')->where('id', $phase->id)->update([
                'description' => $definition?->description,
                'required' => (bool) ($definition?->required ?? false),
            ]);
        });

        DB::table('protocol_phase_supplements')->orderBy('id')->each(function (object $selection): void {
            $supplement = $selection->supplement_id
                ? DB::table('supplements')->where('id', $selection->supplement_id)->first()
                : null;

            if (! $supplement) {
                return;
            }

            DB::table('protocol_phase_supplements')->where('id', $selection->id)->update([
                'dosis_type' => $supplement->dosis_type,
                'dosis' => $supplement->dosis,
                'unit' => $supplement->unit,
                'add_by_default' => (bool) $supplement->add_by_default,
                'max_aantal_in_fase' => $supplement->max_aantal_in_fase,
                'min_aantal_per_week' => $supplement->min_aantal_per_week,
                'rust_periode_in_weken' => $supplement->rust_periode_in_weken,
            ]);
        });
    }
};
