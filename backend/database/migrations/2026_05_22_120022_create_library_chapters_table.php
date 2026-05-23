<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_chapters', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('item_id')->constrained('library_items')->cascadeOnDelete();
            $table->unsignedInteger('order');
            $table->string('title');
            $table->string('start_label', 16)->nullable();
            $table->unsignedInteger('start_sec')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_chapters');
    }
};
