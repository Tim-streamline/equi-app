<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_categories', fn (Blueprint $table) => $table->boolean('is_quick_filter')->default(false));
        Schema::table('library_items', fn (Blueprint $table) => $table->json('featured_suggestion_ids')->nullable());
    }

    public function down(): void
    {
        Schema::table('library_items', fn (Blueprint $table) => $table->dropColumn('featured_suggestion_ids'));
        Schema::table('library_categories', fn (Blueprint $table) => $table->dropColumn('is_quick_filter'));
    }
};
