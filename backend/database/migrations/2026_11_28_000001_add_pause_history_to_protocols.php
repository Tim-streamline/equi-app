<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocols', function (Blueprint $table) {
            // Every pause of the plan, never pruned (ProtocolCompletion). Null until the first status change:
            // pauses from before this column are unknown, so `since` says from when the history is complete.
            $table->json('pause_history')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('protocols', fn (Blueprint $table) => $table->dropColumn('pause_history'));
    }
};
