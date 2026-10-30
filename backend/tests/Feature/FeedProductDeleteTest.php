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

        $this->delete("/admin/feed-scanner/products/{$product->id}", ['confirmed' => true])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Product Dummy · Nepbrok verwijderd.');

        $this->assertNull(Product::find($product->id));
        $this->assertSame(0, ProductVersion::where('product_id', $product->id)->count());
        $this->assertTrue(AuditLog::where('action', 'deleted')->where('target_id', $product->id)->exists());
    }

    public function test_product_with_scans_can_be_deleted_after_confirmation(): void
    {
        $product = $this->product('Gescand voer');
        $user = User::factory()->create();
        ScanResult::create(['user_id' => $user->id, 'product_id' => $product->id, 'product_name' => 'Gescand voer', 'scanned_at' => now(), 'score' => 50, 'rating' => 'Matig']);
        $this->get('/admin/feed-scanner/products')->assertInertia(fn ($page) => $page->where('products.data.0.delete_blockers', []));

        $this->delete("/admin/feed-scanner/products/{$product->id}", ['confirmed' => true])->assertSessionHasNoErrors();

        $this->assertNull(Product::find($product->id));
        $this->assertSame(0, ScanResult::where('product_id', $product->id)->count());
    }

    public function test_only_admins_can_delete(): void
    {
        $product = $this->product('Nepmash');
        $editor = AdminUser::create(['name' => 'Redacteur', 'email' => 'editor@example.com', 'password' => 'password', 'role' => 'content_editor', 'active' => true]);

        $this->actingAs($editor, 'admin')->delete("/admin/feed-scanner/products/{$product->id}", ['confirmed' => true])->assertForbidden();
        $this->assertNotNull(Product::find($product->id));
    }

    public function test_incoming_recipe_relationship_prevents_deletion(): void
    {
        $original = $this->product('Oorspronkelijk recept');
        $related = $this->product('Nieuw recept');
        $related->forceFill(['related_product_id' => $original->id])->save();

        $this->delete("/admin/feed-scanner/products/{$original->id}", ['confirmed' => true])->assertSessionHasErrors([
            'product' => 'Dit product hoort bij de receptgeschiedenis van een ander product. Je kunt het archiveren.',
        ]);

        $this->assertNotNull(Product::find($original->id));
        $this->assertSame($original->id, $related->fresh()->related_product_id);
        $this->assertSame(1, ProductVersion::where('product_id', $original->id)->count());
    }

    public function test_deletion_requires_explicit_confirmation(): void
    {
        $product = $this->product('Niet bevestigd');
        foreach ([[], ['confirmed' => false]] as $data) {
            $this->deleteJson("/admin/feed-scanner/products/{$product->id}", $data)
                ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        }
        $this->assertNotNull(Product::find($product->id));
    }

    public function test_archived_scanned_duplicate_is_deleted_with_its_records_and_only_unshared_files(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $original = $this->product('Origineel voer');
        $duplicate = $this->product('Dubbel voer');
        $duplicate->forceFill(['catalog_status' => 'archived', 'related_product_id' => $original->id])->save();
        $version = ProductVersion::where('product_id', $duplicate->id)->sole();
        $scan = ScanResult::create(['user_id' => User::factory()->create()->id, 'product_id' => $duplicate->id,
            'product_name' => 'Dubbel voer', 'scanned_at' => now(), 'score' => null, 'rating' => 'Voorlopig']);
        $assessment = \App\Models\FeedAssessment::create(['product_version_id' => $version->id,
            'rule_version_id' => \App\Models\FeedRuleVersion::firstOrFail()->id, 'engine_version' => 'test', 'scope' => 'product',
            'is_preview' => true, 'status' => 'complete', 'input_snapshot' => [], 'input_hash' => str_repeat('a', 64), 'rule_snapshot' => [], 'result' => []]);
        \App\Models\FeedAssessmentOverride::create(['assessment_id' => $assessment->id, 'corrected_score' => 5, 'reason' => 'Test', 'actor_name' => 'Reviewer']);
        $submission = \App\Models\FeedScanSubmission::create(['user_id' => $scan->user_id, 'client_id' => (string) \Illuminate\Support\Str::uuid(),
            'product_id' => $duplicate->id, 'product_version_id' => $version->id, 'assessment_id' => $assessment->id,
            'scan_result_id' => $scan->id, 'workflow_version' => 2, 'status' => 'completed']);
        foreach (['private.jpg', 'shared.jpg'] as $path) {
            \Illuminate\Support\Facades\Storage::disk('local')->put($path, 'image');
            \App\Models\FeedLabelAttachment::create(['product_version_id' => $version->id, 'submission_id' => $submission->id,
                'purpose' => 'composition', 'disk' => 'local', 'path' => $path, 'original_name' => $path,
                'mime_type' => 'image/jpeg', 'size_bytes' => 5, 'checksum' => hash('sha256', 'image')]);
        }
        $shared = \App\Models\FeedLabelAttachment::where('path', 'shared.jpg')->sole()->replicate();
        $shared->product_version_id = ProductVersion::where('product_id', $original->id)->sole()->id;
        $shared->submission_id = null;
        $shared->save();
        $this->get('/admin/feed-scanner/products?open='.$duplicate->id)->assertInertia(fn ($page) => $page->where('products.data.0.delete_blockers', []));
        $this->delete("/admin/feed-scanner/products/{$duplicate->id}", ['confirmed' => true])->assertSessionHasNoErrors();
        $this->assertNull(Product::find($duplicate->id));
        $this->assertNull(ProductVersion::find($version->id));
        $this->assertNull(\App\Models\FeedAssessment::find($assessment->id));
        $this->assertNull(\App\Models\FeedScanSubmission::find($submission->id));
        $this->assertNull(ScanResult::find($scan->id));
        $this->assertDatabaseCount('feed_assessment_overrides', 0);
        $this->assertNotNull(Product::find($original->id));
        $this->assertNotNull($shared->fresh());
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing('private.jpg');
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists('shared.jpg');
    }
}
