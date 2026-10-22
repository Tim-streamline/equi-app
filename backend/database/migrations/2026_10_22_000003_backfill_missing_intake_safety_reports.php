<?php

use App\Support\IntakeSafetyReportBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Also repair databases that already ran the original single-report import.
        app(IntakeSafetyReportBackfill::class)->run();
    }

    public function down(): void
    {
        // Never discard an unresolved concern or its recovery evidence on rollback.
    }
};
