<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plus_pages', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->json('content');
            $table->string('hero_path')->nullable();
            $table->string('portrait_path')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('plus_pages'); }
};
