<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Product;
use App\Support\AuditLogger;
use App\Support\FeedCatalog;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArchivePlaceholderFeedProductsTest extends TestCase
{
    use RefreshDatabase;

    private function demoProduct(string $name, array $data = [], string $status = 'legacy', string $versionStatus = 'legacy', ?string $barcode = null): Product
    {
        $product = Product::create(['brand' => 'Pavo', 'name' => $name, 'barcode' => $barcode ?? (string) random_int(1000000000000, 9999999999999), 'category' => 'supplement']);
        AuditLogger::log('demo_seeded', $product, after: $product->only('brand', 'name', 'barcode', 'category'));
        DB::table('product_versions')->where('product_id', $product->id)->delete();
        $json = json_encode(['brand' => 'Pavo', 'name' => $name, 'barcode' => $product->barcode, 'category' => 'supplement', 'ingredients' => [], 'nutrients' => [], ...$data]);
        DB::table('product_versions')->insert(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'number' => 1, 'status' => $versionStatus,
            'data' => $json, 'content_hash' => hash('sha256', $json), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->where('id', $product->id)->update(['catalog_status' => $status]);

        return $product->fresh();
    }

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_27_000009_archive_placeholder_feed_products.php'))->up();
    }

    public function test_empty_demo_products_are_archived_and_lose_their_invented_barcode(): void
    {
        $this->seed(ProductSeeder::class);
        $placeholder = Product::where('name', 'Pavo Vital')->sole();
        FeedCatalog::snapshotLegacyProduct($placeholder);
        $this->migrate();
        $this->migrate();

        $placeholder->refresh();
        $this->assertSame('archived', $placeholder->catalog_status);
        $this->assertNull($placeholder->barcode);
        $this->assertFalse((bool) $placeholder->needs_review);
        $version = json_decode(DB::table('product_versions')->where('product_id', $placeholder->id)->value('data'), true);
        $this->assertNull($version['barcode']);
        $this->assertSame('Pavo Vital', $version['name'], 'history keeps the product itself');
        $this->assertSame(1, DB::table('audit_logs')->where('target_id', $placeholder->id)->where('action', 'archived')->count());
    }

    public function test_products_with_any_real_information_are_left_alone(): void
    {
        $withComposition = $this->demoProduct('Met samenstelling', ['composition_text' => 'Luzerne, lijnzaad']);
        $withAnalysis = $this->demoProduct('Met analyse', ['nutrients' => [['code' => 'sugar', 'value' => 4]]]);
        $edited = $this->demoProduct('Bewerkt concept', versionStatus: 'draft');
        $published = $this->demoProduct('Gepubliceerd', status: 'published');
        $before = DB::table('products')->orderBy('id')->get()->toArray();

        $this->migrate();

        $this->assertEquals($before, DB::table('products')->orderBy('id')->get()->toArray());
        foreach ([$withComposition, $withAnalysis, $edited, $published] as $product) {
            $this->assertNotNull($product->fresh()->barcode);
        }
    }

    public function test_manually_entered_product_keeps_its_barcode_and_version_history(): void
    {
        $admin = AdminUser::create(['name' => 'Catalog admin', 'email' => 'catalog-admin@example.test',
            'password' => 'password', 'role' => 'admin', 'active' => true]);
        // Deliberately use a name from ProductSeeder: a matching name is not provenance.
        $this->actingAs($admin, 'admin')->post('/admin/products', [
            'brand' => 'Pavo', 'name' => 'Pavo Vital', 'barcode' => '8712345678906',
            'category' => 'supplement', 'ingredients' => [],
        ])->assertSessionHasNoErrors();
        $product = Product::where('name', 'Pavo Vital')->sole();
        $this->assertSame('legacy', $product->catalog_status);
        $this->assertPreservedByCleanup($product);
        $this->assertSame('8712345678906', $product->fresh()->barcode);
        $version = json_decode(DB::table('product_versions')->where('product_id', $product->id)->value('data'), true);
        $this->assertSame('8712345678906', $version['barcode']);
    }

    public function test_unmarked_legacy_products_are_preserved_even_without_an_admin_audit_entry(): void
    {
        $product = Product::create(['brand' => 'Pavo', 'name' => 'Pavo Vital', 'barcode' => '7317477472555', 'category' => 'supplement']);
        FeedCatalog::snapshotLegacyProduct($product);

        $this->assertPreservedByCleanup($product);
    }

    public function test_demo_product_with_a_corrected_barcode_is_preserved(): void
    {
        $product = $this->demoProduct('Pavo Vital', barcode: '7317477472555');
        $product->update(['barcode' => '8712345678906']);
        FeedCatalog::snapshotLegacyProduct($product);

        $this->assertPreservedByCleanup($product);
    }

    public function test_demo_product_with_a_different_historical_barcode_is_preserved(): void
    {
        $product = $this->demoProduct('Pavo Vital', ['barcode' => '8712345678906'], barcode: '7317477472555');

        $this->assertPreservedByCleanup($product);
    }

    private function assertPreservedByCleanup(Product $product): void
    {
        $before = $product->fresh()->getAttributes();
        $versions = DB::table('product_versions')->where('product_id', $product->id)->orderBy('number')->get()->toArray();

        $this->migrate();
        $this->migrate();

        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertEquals($versions, DB::table('product_versions')->where('product_id', $product->id)->orderBy('number')->get()->toArray());
        $this->assertDatabaseMissing('audit_logs', ['target_type' => 'Product', 'target_id' => $product->id, 'action' => 'archived']);
    }
}
