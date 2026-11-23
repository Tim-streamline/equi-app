<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The products a customer ticked off in the order list, as "productKey:phaseId".
        Schema::table('protocols', fn (Blueprint $table) => $table->json('ordered_product_keys')->nullable());
    }

    public function down(): void
    {
        Schema::table('protocols', fn (Blueprint $table) => $table->dropColumn('ordered_product_keys'));
    }
};
