<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horse_stats', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->date('measured_at');
            $table->unsignedInteger('weight_kg')->nullable();
            $table->unsignedTinyInteger('energy')->nullable();
            $table->string('stool_score', 8)->nullable();
            $table->string('label')->nullable();
            $table->string('value_label')->nullable();
            $table->string('trend')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->index(['horse_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horse_stats');
    }
};
