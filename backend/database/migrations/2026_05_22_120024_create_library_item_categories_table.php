<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_item_categories', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('item_id')->constrained('library_items')->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained('library_categories')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['item_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_item_categories');
    }
};
