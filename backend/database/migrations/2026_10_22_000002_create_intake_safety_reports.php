<?php

use App\Support\IntakeSafetyReportBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('intake_safety_reports')) {
            Schema::create('intake_safety_reports', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
                // Deleting an intake must never discard an unresolved horse concern.
                $table->foreignUuid('intake_booking_id')->nullable()->constrained('intake_bookings')->nullOnDelete();
                $table->json('state');
                $table->timestamps();
                $table->index(['horse_id', 'intake_booking_id']);
            });
        }
        app(IntakeSafetyReportBackfill::class)->run();
    }

    public function down(): void
    {
        // Retain safety reports and recovery evidence on application rollback.
    }
};
