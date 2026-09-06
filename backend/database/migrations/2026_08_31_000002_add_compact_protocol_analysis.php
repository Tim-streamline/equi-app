<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocol_analyses', function (Blueprint $table) {
            // Keep the original notes intact; a therapist authors the customer summary.
            $table->text('summary')->nullable();
            $table->json('focus_points')->nullable();
            $table->json('observations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('protocol_analyses', fn (Blueprint $table) => $table->dropColumn(['summary', 'focus_points', 'observations']));
    }
};
