<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_ingredients', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('scan_id')->constrained('scan_results')->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name'); // snapshot in case ingredient deleted
            $table->string('tag', 16); // good | warn | danger
            $table->text('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_ingredients');
    }
};
