<?php

use App\Support\Brand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'admin_users' => ['name'],
            'audit_logs' => ['actor_name', 'target_label'],
            'plans' => ['name', 'description'],
            'timeline_events' => ['message'],
        ] as $table => $columns) {
            DB::table($table)->orderBy('id')->chunk(200, function ($rows) use ($table, $columns) {
                foreach ($rows as $row) {
                    $changes = [];
                    foreach ($columns as $column) {
                        if (is_string($row->$column) && ($normalized = Brand::normalize($row->$column)) !== $row->$column) {
                            $changes[$column] = $normalized;
                        }
                    }
                    if ($changes) {
                        DB::table($table)->where('id', $row->id)->update($changes);
                    }
                }
            });
        }
        // Normalize display values recursively, keeping JSON keys and embedded URLs intact.
        $normalize = function ($value) use (&$normalize) {
            if (is_string($value)) {
                return Brand::normalize($value);
            }
            if (is_array($value)) {
                return array_map($normalize, $value);
            }

            return $value;
        };
        foreach (DB::table('plus_pages')->get(['id', 'content']) as $page) {
            $content = json_decode($page->content, true, flags: JSON_THROW_ON_ERROR);
            $normalized = $normalize($content);
            if ($normalized !== $content) {
                DB::table('plus_pages')->where('id', $page->id)->update(['content' => json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
        }
    }

    public function down(): void
    {
        // Display copy cannot be restored without undoing independently authored text.
    }
};
