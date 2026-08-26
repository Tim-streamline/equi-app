<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('observations', 'protocol_task_id')) {
            Schema::table('observations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('protocol_task_id');
            });
        }

        Schema::dropIfExists('protocol_task_completions');
        Schema::dropIfExists('protocol_tasks');
    }

    public function down(): void
    {
        // Protocol tasks were intentionally removed from the product model.
    }
};
