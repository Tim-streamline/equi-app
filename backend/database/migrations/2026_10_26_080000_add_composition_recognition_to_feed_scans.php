<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_scan_submissions', function (Blueprint $table) {
            $table->foreignUuid('product_id')->nullable()->change();
            $table->foreignUuid('product_version_id')->nullable()->change();
            $table->unsignedSmallInteger('workflow_version')->default(1);
            $table->foreignUuid('reference_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignUuid('reference_version_id')->nullable()->constrained('product_versions')->restrictOnDelete();
            $table->jsonb('recognition')->nullable();
            $table->string('submitted_name')->nullable();
        });
        Schema::table('feed_label_attachments', fn (Blueprint $table) => $table->foreignUuid('product_version_id')->nullable()->change());
    }

    public function down(): void
    {
        throw new RuntimeException('Inzendingen en receptgeschiedenis worden niet destructief teruggedraaid.');
    }
};
