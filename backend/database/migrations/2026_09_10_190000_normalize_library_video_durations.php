<?php

use App\Support\LibraryVideoDuration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('library_items')->where('format', 'video')->orderBy('id')->chunkById(100, function ($items) {
            foreach ($items as $item) {
                $minutes = LibraryVideoDuration::minutes($item->duration_label, $item->duration_sec);
                if ($minutes !== null) {
                    DB::table('library_items')->where('id', $item->id)->update([
                        'duration_label' => LibraryVideoDuration::label($minutes),
                        'duration_sec' => (int) round($minutes * 60),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // The old free-text spelling cannot be reconstructed; keep the valid durations.
    }
};
