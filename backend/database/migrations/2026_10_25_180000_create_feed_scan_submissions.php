<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_scan_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_id');
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_version_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('assessment_id')->nullable()->constrained('feed_assessments')->restrictOnDelete();
            $table->foreignUuid('scan_result_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('draft')->index();
            $table->jsonb('photo_ids')->default('{}');
            $table->jsonb('extraction')->nullable();
            $table->jsonb('public_result')->nullable();
            $table->string('error_code')->nullable();
            $table->text('message')->nullable();
            $table->uuid('processing_token')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'client_id']);
        });
        Schema::table('feed_label_attachments', function (Blueprint $table) {
            $table->foreignUuid('submission_id')->nullable()->constrained('feed_scan_submissions')->nullOnDelete();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
        });
        // Missing results are unknown, never a fabricated zero. Existing values remain intact.
        Schema::table('scan_results', fn (Blueprint $table) => $table->unsignedTinyInteger('score')->nullable()->change());
    }

    public function down(): void
    {
        throw new RuntimeException('Scannerinzendingen en beoordelingshistorie worden niet destructief teruggedraaid.');
    }
};
