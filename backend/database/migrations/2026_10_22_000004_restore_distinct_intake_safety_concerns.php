<?php

use App\Support\IntakeSafetyReportBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Repair databases whose earlier backfill grouped history by intake alone.
        app(IntakeSafetyReportBackfill::class)->run();
    }

    public function down(): void
    {
        // Keep restored concerns and any subsequent recovery confirmations.
    }
};
