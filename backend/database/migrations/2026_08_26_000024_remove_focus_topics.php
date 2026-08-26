<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('library_item_focus');
        Schema::dropIfExists('horse_focus');
        Schema::dropIfExists('focus_topics');
    }

    public function down(): void
    {
        Schema::create('focus_topics', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->string('slug')->unique();
            $table->string('icon', 8)->nullable();
            $table->string('title');
            $table->string('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('horse_focus', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('focus_topic_id')->constrained()->cascadeOnDelete();
            $table->string('extra_label')->nullable();
            $table->timestamp('added_at')->nullable();
            $table->timestamps();
            $table->unique(['horse_id', 'focus_topic_id']);
        });

        Schema::create('library_item_focus', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('item_id')->constrained('library_items')->cascadeOnDelete();
            $table->foreignUuid('focus_topic_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['item_id', 'focus_topic_id']);
        });
    }
};
