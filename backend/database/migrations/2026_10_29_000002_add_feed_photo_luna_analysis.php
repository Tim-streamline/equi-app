<?php

use App\Models\FeedAnalysisPrompt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_analysis_prompts', function (Blueprint $table) {
            $table->string('type')->primary();
            $table->text('prompt');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });
        foreach (FeedAnalysisPrompt::defaults() as $type => $prompt) {
            DB::table('feed_analysis_prompts')->insert(['type' => $type, 'prompt' => $prompt, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('feed_scan_submissions', function (Blueprint $table) {
            foreach (['ingredients', 'nutrition', 'product'] as $slot) {
                $prefix = $slot.'_photo_';
                $table->uuid($prefix.'analysis_token')->nullable();
                $table->timestampTz($prefix.'analysis_started_at')->nullable();
                foreach (['status', 'model', 'error_code'] as $field) {
                    $table->string($prefix.'luna_'.$field)->nullable();
                }
                $table->text($prefix.'luna_prompt')->nullable();
                $table->unsignedInteger($prefix.'luna_prompt_version')->nullable();
                $table->jsonb($prefix.'luna_data')->nullable();
                $table->jsonb($prefix.'luna_usage')->nullable();
                $table->timestampTz($prefix.'luna_processed_at')->nullable();
            }
        });
        Schema::table('feed_label_attachments', function (Blueprint $table) {
            $table->jsonb('ocr')->nullable();
            $table->jsonb('luna')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('feed_label_attachments', fn (Blueprint $table) => $table->dropColumn(['ocr', 'luna']));
        Schema::table('feed_scan_submissions', function (Blueprint $table) {
            foreach (['ingredients', 'nutrition', 'product'] as $slot) {
                $table->dropColumn(array_map(fn ($field) => $slot.'_photo_'.$field, ['analysis_token', 'analysis_started_at', 'luna_status', 'luna_model', 'luna_error_code', 'luna_prompt', 'luna_prompt_version', 'luna_data', 'luna_usage', 'luna_processed_at']));
            }
        });
        Schema::dropIfExists('feed_analysis_prompts');
    }
};
