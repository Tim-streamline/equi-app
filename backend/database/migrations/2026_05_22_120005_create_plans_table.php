<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->string('slug')->unique();
            $table->string('label')->nullable();
            $table->string('name');
            $table->unsignedInteger('price_cents');
            $table->string('currency', 8)->default('EUR');
            $table->string('interval', 16); // monthly | one_time
            $table->string('price_suffix')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_recommended')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
