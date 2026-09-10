<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_items', function (Blueprint $table) {
            $table->string('thumbnail_mode', 16)->default('auto');
        });
        DB::table('library_items')->whereNotNull('hero_image_url')->where('hero_image_url', '!=', '')
            ->update(['thumbnail_mode' => 'manual']);
        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('thumbnail_path')->nullable();
            $table->string('thumbnail_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', fn (Blueprint $table) => $table->dropColumn(['thumbnail_path', 'thumbnail_url']));
        Schema::table('library_items', fn (Blueprint $table) => $table->dropColumn('thumbnail_mode'));
    }
};
