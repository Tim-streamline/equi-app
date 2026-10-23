<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_items', function (Blueprint $table): void {
            $table->string('series_name')->nullable();
            $table->unsignedSmallInteger('series_part')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('library_items', function (Blueprint $table): void {
            $table->dropColumn(['series_name', 'series_part']);
        });
    }
};
