<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_progress', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('library_items')->cascadeOnDelete();
            $table->unsignedInteger('position_sec')->default(0);
            $table->decimal('progress', 5, 4)->default(0); // 0..1
            $table->boolean('completed')->default(false);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_progress');
    }
};
