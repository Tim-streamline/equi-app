<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_rule_versions', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('admin_users')->nullOnDelete();
        });
        Schema::table('feed_assessment_profiles', function (Blueprint $table) {
            $table->foreignUuid('active_rule_version_id')->nullable()->constrained('feed_rule_versions')->restrictOnDelete();
        });
        Schema::create('feed_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_version_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('rule_version_id')->constrained('feed_rule_versions')->restrictOnDelete();
            $table->string('engine_version');
            $table->string('scope')->default('product');
            $table->foreignUuid('horse_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_preview');
            $table->string('status');
            $table->decimal('automatic_score', 5, 2)->nullable();
            $table->decimal('score_cap', 5, 2)->nullable();
            $table->jsonb('input_snapshot');
            $table->string('input_hash', 64);
            $table->jsonb('rule_snapshot');
            $table->jsonb('result');
            $table->jsonb('changes')->nullable();
            $table->uuid('previous_assessment_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps(6);
            $table->index(['product_version_id', 'created_at']);
        });
        Schema::table('feed_assessments', function (Blueprint $table) {
            $table->foreign('previous_assessment_id')->references('id')->on('feed_assessments')->restrictOnDelete();
        });
        Schema::create('feed_assessment_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_id')->constrained('feed_assessments')->restrictOnDelete();
            $table->decimal('corrected_score', 5, 2);
            $table->text('reason');
            $table->foreignUuid('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('actor_name');
            $table->timestamps(6);
            $table->index(['assessment_id', 'created_at']);
        });
        // Append, never rewrite existing policy versions or activate their drafts.
        $caps = json_decode(file_get_contents(database_path('data/feed-caps-2026-10-23.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (DB::table('feed_assessment_profiles')->get() as $profile) {
            $latest = DB::table('feed_rule_versions')->where('profile_id', $profile->id)->orderByDesc('number')->first();
            if (! $latest) {
                continue;
            }
            $data = array_merge(json_decode($latest->data, true, flags: JSON_THROW_ON_ERROR), $caps);
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            DB::table('feed_rule_versions')->insert(['id' => (string) Str::uuid(), 'profile_id' => $profile->id, 'number' => $latest->number + 1,
                'schema_version' => 2, 'status' => 'draft', 'calibration_status' => 'uncalibrated', 'data' => $json,
                'content_hash' => hash('sha256', $json), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Beoordelingshistorie wordt niet destructief teruggedraaid. Gebruik een gecontroleerd herstel.');
    }
};
