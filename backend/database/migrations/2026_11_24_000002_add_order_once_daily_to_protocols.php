<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The customer can give only one gift a day: the order list then counts one gift for products with more.
        Schema::table('protocols', fn (Blueprint $table) => $table->boolean('order_once_daily')->default(false));
    }

    public function down(): void
    {
        Schema::table('protocols', fn (Blueprint $table) => $table->dropColumn('order_once_daily'));
    }
};
