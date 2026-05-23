<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_task_completions', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('task_id')->constrained('protocol_tasks')->cascadeOnDelete();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->boolean('done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'date']);
            $table->index(['horse_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_task_completions');
    }
};
