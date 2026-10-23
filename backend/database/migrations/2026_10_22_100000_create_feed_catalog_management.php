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
        foreach (['ingredients', 'products'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('catalog_status')->default('legacy')->index();
                $table->text('internal_notes')->nullable();
                $table->uuid('published_version_id')->nullable();
            });
        }
        Schema::create('ingredient_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ingredient_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('status')->default('draft');
            $table->jsonb('data');
            $table->foreignUuid('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignUuid('published_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['ingredient_id', 'number']);
        });
        Schema::create('ingredient_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ingredient_revision_id')->constrained()->restrictOnDelete();
            $table->string('term');
            $table->string('normalized_term')->index();
            $table->string('language', 12)->default('nl');
            $table->string('kind')->default('exact');
            $table->unique(['ingredient_revision_id', 'normalized_term', 'language', 'kind'], 'ingredient_term_unique');
        });
        Schema::create('feed_assessment_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('feed_knowledge_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('explanation')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('status')->default('draft');
            $table->foreignUuid('library_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('feed_rule_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('feed_assessment_profiles')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('status')->default('draft');
            $table->string('calibration_status')->default('uncalibrated');
            $table->unsignedInteger('schema_version')->default(1);
            $table->jsonb('data');
            $table->string('content_hash', 64);
            $table->foreignUuid('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['profile_id', 'number']);
        });
        Schema::create('product_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('status')->default('draft')->index();
            $table->foreignUuid('profile_id')->nullable()->constrained('feed_assessment_profiles')->restrictOnDelete();
            $table->jsonb('data');
            $table->string('content_hash', 64);
            $table->foreignUuid('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'number']);
        });
        Schema::create('feed_label_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_version_id')->constrained()->restrictOnDelete();
            $table->string('purpose');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64);
            $table->foreignUuid('uploaded_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });
        // Add pointers after their target tables exist. Keep old public fields intact.
        Schema::table('ingredients', fn (Blueprint $t) => $t->foreign('published_version_id')->references('id')->on('ingredient_revisions')->restrictOnDelete());
        Schema::table('products', fn (Blueprint $t) => $t->foreign('published_version_id')->references('id')->on('product_versions')->restrictOnDelete());

        $now = now();
        foreach (['standard' => 'Standaardvoer', 'balancer' => 'Balancer'] as $code => $name) {
            $profileId = (string) Str::uuid();
            DB::table('feed_assessment_profiles')->insert(['id' => $profileId, 'code' => $code, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            $policy = file_get_contents(database_path('data/feed-policy-2026-10-22.json'));
            DB::table('feed_rule_versions')->insert(['id' => (string) Str::uuid(), 'profile_id' => $profileId, 'number' => 1,
                'status' => 'draft', 'calibration_status' => 'uncalibrated', 'data' => $policy, 'content_hash' => hash('sha256', $policy),
                'created_at' => $now, 'updated_at' => $now]);
        }
        $this->backfillLegacy();
    }

    public function backfillLegacy(): void
    {
        $now = now();
        // Backfill only what actually exists. No invented analysis, aliases or approvals.
        DB::table('ingredients')->orderBy('id')->each(function ($ingredient) use ($now) {
            if (DB::table('ingredient_revisions')->where('ingredient_id', $ingredient->id)->exists()) {
                return;
            }
            DB::table('ingredient_revisions')->insert([
                'id' => (string) Str::uuid(), 'ingredient_id' => $ingredient->id, 'number' => 1, 'status' => 'legacy',
                'data' => json_encode(['name' => $ingredient->name, 'explanation' => $ingredient->description, 'default_tag' => $ingredient->default_tag, 'terms' => []]),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });
        DB::table('products')->orderBy('id')->each(function ($product) use ($now) {
            if (DB::table('product_versions')->where('product_id', $product->id)->exists()) {
                return;
            }
            $rows = DB::table('ingredient_product')->where('product_id', $product->id)->orderBy('order')->get();
            $data = ['brand' => $product->brand, 'name' => $product->name, 'barcode' => $product->barcode, 'category' => $product->category,
                'ingredients' => $rows->map(fn ($row) => ['ingredient_id' => $row->ingredient_id, 'order' => $row->order, 'amount' => $row->amount])->all(), 'nutrients' => []];
            $json = json_encode($data);
            DB::table('product_versions')->insert(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'number' => 1, 'status' => 'legacy', 'data' => $json,
                'content_hash' => hash('sha256', $json), 'created_at' => $now, 'updated_at' => $now]);
        });
    }

    public function down(): void
    {
        // Deliberately refuse destructive rollback once catalog work has been saved.
        throw new RuntimeException('De voercatalogus bevat versiehistorie. Herstel via een gecontroleerde back-up, niet via een destructieve rollback.');
    }
};
