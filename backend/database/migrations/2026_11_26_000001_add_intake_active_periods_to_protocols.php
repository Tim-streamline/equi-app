<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocols', function (Blueprint $table) {
            // Older pauses have no reliable start date.
            $table->json('intake_active_periods')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('protocols', fn (Blueprint $table) => $table->dropColumn('intake_active_periods'));
    }
};
