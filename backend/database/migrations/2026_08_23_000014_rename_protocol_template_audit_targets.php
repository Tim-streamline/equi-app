<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameTargets([
            'ProtocolType' => 'ProtocolTemplate',
            'ProtocolTypePhase' => 'ProtocolTemplatePhase',
            'ProtocolTypePhaseWeek' => 'ProtocolTemplatePhaseWeek',
        ]);
    }

    public function down(): void
    {
        $this->renameTargets([
            'ProtocolTemplate' => 'ProtocolType',
            'ProtocolTemplatePhase' => 'ProtocolTypePhase',
            'ProtocolTemplatePhaseWeek' => 'ProtocolTypePhaseWeek',
        ]);
    }

    /** @param array<string, string> $renames */
    private function renameTargets(array $renames): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        foreach ($renames as $from => $to) {
            DB::table('audit_logs')
                ->where('target_type', $from)
                ->update(['target_type' => $to]);
        }
    }
};
