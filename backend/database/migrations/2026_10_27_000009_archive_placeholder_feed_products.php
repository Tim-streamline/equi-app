<?php

use App\Models\Product;
use App\Support\AuditLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The demo seeder created products with real brand names, invented barcodes and no label data.
 * Archive those placeholders and drop their invented barcodes, so a real scan of the same product
 * is never matched to an empty record. Require explicit seeder provenance: older unmarked
 * records cannot safely be distinguished from manually entered products and stay untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('products')->where('catalog_status', 'legacy')->orderBy('id')->lockForUpdate()->get()
                ->each(function ($product): void {
                    if (! $this->placeholder($product)) {
                        return;
                    }
                    DB::table('product_versions')->where('product_id', $product->id)->get()->each(function ($version): void {
                        $data = json_decode($version->data, true) ?: [];
                        if (! array_key_exists('barcode', $data) || $data['barcode'] === null) {
                            return;
                        }
                        $data['barcode'] = null;
                        $json = json_encode($data);
                        DB::table('product_versions')->where('id', $version->id)->update(['data' => $json, 'content_hash' => hash('sha256', $json)]);
                    });
                    DB::table('products')->where('id', $product->id)->update(['catalog_status' => 'archived', 'needs_review' => false, 'barcode' => null, 'updated_at' => now()]);
                    if ($model = Product::find($product->id)) {
                        AuditLogger::log('archived', $model, reason: 'Voorbeeldproduct zonder etiketgegevens; verzonnen barcode verwijderd.');
                    }
                });
        });
    }

    public function down(): void
    {
        // Archived placeholders and their removed barcodes are not restored.
    }

    /** Only verified seeder records whose identity and empty label data are still unchanged. */
    private function placeholder(object $product): bool
    {
        $productId = $product->id;
        $provenance = DB::table('audit_logs')->where('target_type', 'Product')->where('target_id', $productId)
            ->where('action', 'demo_seeded')->value('after');
        $demo = json_decode($provenance ?? 'null', true);
        if (! is_array($demo) || ! filled($demo['barcode'] ?? null) || ! $this->matchesDemo((array) $product, $demo)) {
            return false;
        }
        if (Schema::hasTable('ingredient_product') && DB::table('ingredient_product')->where('product_id', $productId)->exists()) {
            return false;
        }
        $versions = DB::table('product_versions')->where('product_id', $productId)->get();
        foreach ($versions as $version) {
            $data = json_decode($version->data, true);
            if (! is_array($data) || ! $this->matchesDemo($data, $demo)
                || $version->status !== 'legacy' || filled($data['composition_text'] ?? null) || ! empty($data['ingredients'])
                || ! empty($data['nutrients']) || filled($data['usage'] ?? null)) {
                return false;
            }
        }

        return ! DB::table('feed_label_attachments')->whereIn('product_version_id', $versions->pluck('id'))->exists();
    }

    private function matchesDemo(array $data, array $demo): bool
    {
        foreach (['brand', 'name', 'barcode', 'category'] as $key) {
            if (! array_key_exists($key, $data) || ! array_key_exists($key, $demo) || $data[$key] !== $demo[$key]) {
                return false;
            }
        }

        return true;
    }
};
