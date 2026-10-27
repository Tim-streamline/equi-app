<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArchivePlaceholderFeedProductsTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, array $data = [], string $status = 'legacy', string $versionStatus = 'legacy', ?string $barcode = null): Product
    {
        $product = Product::create(['brand' => 'Pavo', 'name' => $name, 'barcode' => $barcode ?? (string) random_int(1000000000000, 9999999999999), 'category' => 'supplement']);
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
        $placeholder = $this->product('Pavo Vital', barcode: '7317477472555');
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
        $withComposition = $this->product('Met samenstelling', ['composition_text' => 'Luzerne, lijnzaad']);
        $withAnalysis = $this->product('Met analyse', ['nutrients' => [['code' => 'sugar', 'value' => 4]]]);
        $edited = $this->product('Bewerkt concept', versionStatus: 'draft');
        $published = $this->product('Gepubliceerd', status: 'published');
        $before = DB::table('products')->orderBy('id')->get()->toArray();

        $this->migrate();

        $this->assertEquals($before, DB::table('products')->orderBy('id')->get()->toArray());
        foreach ([$withComposition, $withAnalysis, $edited, $published] as $product) {
            $this->assertNotNull($product->fresh()->barcode);
        }
    }
}
