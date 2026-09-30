<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Display text only: keep account IDs, login addresses, slugs and history timestamps.
        foreach ([
            'admin_users' => ['name'],
            'audit_logs' => ['actor_name', 'target_label'],
            'plans' => ['name', 'description'],
            'timeline_events' => ['message'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->whereRaw("{$column} ~* ?", ['EquiNova|EquiApp'])->update([
                    $column => DB::raw("regexp_replace({$column}, 'EquiNova|EquiApp', 'Equi App', 'gi')"),
                ]);
            }
        }

        DB::table('plus_pages')->whereRaw('content::text ~* ?', ['EquiNova|EquiApp'])->update([
            'content' => DB::raw("regexp_replace(content::text, 'EquiNova|EquiApp', 'Equi App', 'gi')::json"),
        ]);
    }

    public function down(): void
    {
        // The new name can also occur in independently authored content; do not revert it.
    }
};
