<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('intake_bookings')
            ->whereNotNull('submitted_at')
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereIn('protocol_id', DB::table('protocols')->whereNotNull('published_at')->select('id'))
            ->update(['status' => 'done', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Keep completed reviews; their original review state cannot be recovered reliably.
    }
};
