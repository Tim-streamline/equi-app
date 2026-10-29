<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->foreignUuid('photo_analysis_parent_id')->nullable()->constrained('product_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_versions', fn (Blueprint $table) => $table->dropConstrainedForeignId('photo_analysis_parent_id'));
    }
};
