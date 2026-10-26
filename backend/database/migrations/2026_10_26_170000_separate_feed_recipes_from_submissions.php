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
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
            $table->index('barcode');
            $table->foreignUuid('related_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('scan_fingerprint', 64)->nullable()->unique();
        });
        Schema::table('product_versions', function (Blueprint $table) {
            // Old scan snapshots keep their original versions; migrated review
            // work is explicitly excluded from the original product's current draft.
            $table->foreignUuid('superseded_by_product_id')->nullable()->constrained('products')->restrictOnDelete();
        });

        $this->separateExistingSubmissions();
    }

    public function separateExistingSubmissions(): void
    {
        DB::transaction(function () {
            $submissions = DB::table('feed_scan_submissions')->whereNotNull('product_id')->get();
            foreach ($submissions as $submission) {
                $versions = DB::table('product_versions')->where('product_id', $submission->product_id)
                    ->where('submission_id', $submission->id)->orderBy('number')->get();
                $latest = $versions->last();
                if (! $latest || ! in_array($latest->status, ['draft', 'needs_info', 'rejected'])) {
                    continue; // Published recipes and completed assessment snapshots are never rewritten.
                }
                $original = DB::table('products')->where('id', $submission->product_id)->first();
                $otherVersions = DB::table('product_versions')->where('product_id', $original->id)
                    ->whereNull('superseded_by_product_id')->whereNotIn('id', $versions->pluck('id'))->exists();
                if (! $original->published_version_id && ! $otherVersions) {
                    // Already a separate product. Only remove the old ownership model.
                    DB::table('product_versions')->whereIn('id', $versions->pluck('id'))->update(['submission_id' => null]);
                    continue;
                }
                $data = json_decode($latest->data, true);
                $id = (string) Str::uuid();
                $product = (array) $original;
                unset($product['id']);
                DB::table('products')->insert(array_replace($product, ['id' => $id, 'brand' => $data['brand'], 'name' => $data['name'],
                    'barcode' => null, 'category' => $data['category'] ?? null, 'catalog_status' => 'draft',
                    'published_version_id' => null, 'needs_review' => $latest->status !== 'rejected',
                    'related_product_id' => $original->id, 'scan_fingerprint' => null, 'created_at' => now(), 'updated_at' => now()]));
                $copied = [];
                foreach ($versions as $version) {
                    $copy = (array) $version;
                    $copy['id'] = (string) Str::uuid();
                    $copy['product_id'] = $id;
                    $copy['submission_id'] = null;
                    $copy['number'] = count($copied) + 1;
                    DB::table('product_versions')->insert($copy);
                    $copied[$version->id] = $copy['id'];
                    foreach (DB::table('feed_label_attachments')->where('product_version_id', $version->id)->get() as $attachment) {
                        $photo = (array) $attachment;
                        $photo['id'] = (string) Str::uuid();
                        $photo['product_version_id'] = $copy['id'];
                        DB::table('feed_label_attachments')->insert($photo);
                    }
                }
                DB::table('product_versions')->whereIn('id', $versions->pluck('id'))->update(['superseded_by_product_id' => $id]);
                DB::table('feed_scan_submissions')->where('id', $submission->id)->update(['product_id' => $id,
                    'product_version_id' => $copied[$submission->product_version_id] ?? $copied[$latest->id],
                    'reference_product_id' => $submission->reference_product_id ?? $original->id]);
                $pending = DB::table('product_versions')->where('product_id', $original->id)->whereNull('superseded_by_product_id')->orderByDesc('number')->first();
                DB::table('products')->where('id', $original->id)->update(['needs_review' => $pending && in_array($pending->status, ['draft', 'needs_info', 'legacy'])]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Gescheiden recepten en bestaande scanhistorie worden niet samengevoegd bij rollback.');
    }
};
