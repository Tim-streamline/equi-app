<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_product', function (Blueprint $table) {
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('order');
            $table->string('amount')->nullable();
            $table->timestamps();
            $table->primary(['product_id', 'ingredient_id']);
            $table->index('ingredient_id');
            $table->index(['product_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_product');
    }
};
