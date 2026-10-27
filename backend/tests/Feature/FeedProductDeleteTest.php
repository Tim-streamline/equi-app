<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\ScanResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedProductDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(AdminUser::create(['name' => 'Feed admin', 'email' => 'feed-admin@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]), 'admin');
    }

    private function product(string $name, bool $approve = false): Product
    {
        $this->post('/admin/feed-scanner/products', ['brand' => 'Dummy', 'name' => $name, 'barcode' => null, 'category' => 'complementary',
            'composition_text' => '', 'source' => '', 'usage' => '', 'internal_notes' => '', 'ingredients' => [], 'nutrients' => [], 'approve' => $approve])->assertSessionHasNoErrors();

        return Product::where('name', $name)->sole();
    }

    public function test_unused_product_is_deleted_with_its_versions_and_logged(): void
    {
        $product = $this->product('Nepbrok', approve: true);
        $this->get('/admin/feed-scanner/products')->assertInertia(fn ($page) => $page->where('products.data.0.delete_blockers', []));

        $this->delete("/admin/feed-scanner/products/{$product->id}")->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Product Dummy · Nepbrok verwijderd.');

        $this->assertNull(Product::find($product->id));
        $this->assertSame(0, ProductVersion::where('product_id', $product->id)->count());
        $this->assertTrue(AuditLog::where('action', 'deleted')->where('target_id', $product->id)->exists());
    }

    public function test_product_with_scans_is_kept_and_must_be_archived(): void
    {
        $product = $this->product('Gescand voer');
        $user = User::factory()->create();
        ScanResult::create(['user_id' => $user->id, 'product_id' => $product->id, 'product_name' => 'Gescand voer', 'scanned_at' => now(), 'score' => 50, 'rating' => 'Matig']);
        $this->get('/admin/feed-scanner/products')->assertInertia(fn ($page) => $page->where('products.data.0.delete_blockers', ['scans']));

        $this->delete("/admin/feed-scanner/products/{$product->id}")->assertSessionHasErrors(['product' => 'Dit product kan niet worden verwijderd, want het heeft scans. Archiveer het in plaats daarvan.']);

        $this->assertNotNull(Product::find($product->id));
    }

    public function test_only_admins_can_delete(): void
    {
        $product = $this->product('Nepmash');
        $editor = AdminUser::create(['name' => 'Redacteur', 'email' => 'editor@example.com', 'password' => 'password', 'role' => 'content_editor', 'active' => true]);

        $this->actingAs($editor, 'admin')->delete("/admin/feed-scanner/products/{$product->id}")->assertForbidden();
        $this->assertNotNull(Product::find($product->id));
    }

    public function test_incoming_recipe_relationship_prevents_deletion(): void
    {
        $original = $this->product('Oorspronkelijk recept');
        $related = $this->product('Nieuw recept');
        $related->forceFill(['related_product_id' => $original->id])->save();

        $this->delete("/admin/feed-scanner/products/{$original->id}")->assertSessionHasErrors([
            'product' => 'Dit product hoort bij de receptgeschiedenis van een ander product. Je kunt het archiveren.',
        ]);

        $this->assertNotNull(Product::find($original->id));
        $this->assertSame($original->id, $related->fresh()->related_product_id);
        $this->assertSame(1, ProductVersion::where('product_id', $original->id)->count());
    }
}
