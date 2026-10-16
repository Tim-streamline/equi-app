<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plus_intake_entitlements', function (Blueprint $table) {
            $table->dropForeign(['horse_id']);
            $table->uuid('horse_id')->nullable()->change();
            // Keep the consumed entitlement even when its intake and horse are deleted.
            $table->foreign('horse_id')->references('id')->on('horses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('plus_intake_entitlements', function (Blueprint $table) {
            $table->dropForeign(['horse_id']);
            // Deleted horses cannot be restored: retain nullable historical grants.
            $table->foreign('horse_id')->references('id')->on('horses')->restrictOnDelete();
        });
    }
};
