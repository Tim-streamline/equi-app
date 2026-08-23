<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameTable('protocol_types', 'protocol_templates');
        $this->renameTable('protocol_type_phases', 'protocol_template_phases');
        $this->renameTable('protocol_type_phase_weeks', 'protocol_template_phase_weeks');

        $this->renameColumn('protocol_template_phases', 'protocol_type_id', 'protocol_template_id');
        $this->renameColumn('protocol_template_phase_weeks', 'protocol_type_phase_id', 'protocol_template_phase_id');
        $this->renameColumn('protocols', 'protocol_type_id', 'protocol_template_id');
        $this->renameColumn('protocols', 'protocol_type_name', 'protocol_template_name');
        $this->renameColumn('protocol_phases', 'protocol_type_phase_id', 'protocol_template_phase_id');
        $this->renameColumn('protocol_phase_weeks', 'protocol_type_phase_week_id', 'protocol_template_phase_week_id');
        $this->renameColumn('supplements', 'protocol_type_phase_id', 'protocol_template_phase_id');
        $this->renameColumn('supplement_weeks', 'protocol_type_phase_week_id', 'protocol_template_phase_week_id');
    }

    public function down(): void
    {
        $this->renameColumn('supplement_weeks', 'protocol_template_phase_week_id', 'protocol_type_phase_week_id');
        $this->renameColumn('supplements', 'protocol_template_phase_id', 'protocol_type_phase_id');
        $this->renameColumn('protocol_phase_weeks', 'protocol_template_phase_week_id', 'protocol_type_phase_week_id');
        $this->renameColumn('protocol_phases', 'protocol_template_phase_id', 'protocol_type_phase_id');
        $this->renameColumn('protocols', 'protocol_template_name', 'protocol_type_name');
        $this->renameColumn('protocols', 'protocol_template_id', 'protocol_type_id');
        $this->renameColumn('protocol_template_phase_weeks', 'protocol_template_phase_id', 'protocol_type_phase_id');
        $this->renameColumn('protocol_template_phases', 'protocol_template_id', 'protocol_type_id');

        $this->renameTable('protocol_template_phase_weeks', 'protocol_type_phase_weeks');
        $this->renameTable('protocol_template_phases', 'protocol_type_phases');
        $this->renameTable('protocol_templates', 'protocol_types');
    }

    private function renameTable(string $from, string $to): void
    {
        if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
            Schema::rename($from, $to);
        }
    }

    private function renameColumn(string $table, string $from, string $to): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $from) && ! Schema::hasColumn($table, $to)) {
            Schema::table($table, function (Blueprint $blueprint) use ($from, $to): void {
                $blueprint->renameColumn($from, $to);
            });
        }
    }
};
