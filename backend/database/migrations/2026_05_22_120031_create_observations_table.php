<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observations', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('author_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->text('note')->nullable();
            $table->unsignedTinyInteger('mood')->nullable(); // 1..5
            $table->string('stool_score', 8)->nullable();
            $table->foreignUuid('protocol_task_id')->nullable()->constrained('protocol_tasks')->nullOnDelete();
            $table->timestamps();
            $table->index(['horse_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observations');
    }
};
