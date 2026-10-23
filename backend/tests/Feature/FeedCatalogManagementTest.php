<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\FeedKnowledgeCard;
use App\Models\FeedLabelAttachment;
use App\Models\FeedRuleVersion;
use App\Models\Ingredient;
use App\Models\IngredientRevision;
use App\Models\Product;
use App\Models\ProductVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FeedCatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AdminUser::create(['name' => 'Feed admin', 'email' => 'feed-admin@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $this->actingAs($this->admin, 'admin');
    }

    private function ingredient(array $overrides = []): array
    {
        return array_replace(['name' => 'Lijnzaad', 'category' => 'Zaden', 'explanation' => 'Herkenbaar ingrediënt.', 'assessment' => 'preferred',
            'exceptions' => '', 'default_tag' => null, 'library_item_id' => null, 'internal_notes' => 'Niet voor de app',
            'terms' => [['term' => 'Linseed', 'language' => 'en', 'kind' => 'exact']]], $overrides);
    }

    private function product(array $overrides = []): array
    {
        return array_replace(['brand' => 'Voorbeeldmerk', 'name' => 'Voorbeeldvoer', 'barcode' => null, 'category' => 'complementary',
            'composition_text' => 'Lijnzaad', 'source' => 'Etiket', 'usage' => '', 'internal_notes' => 'Intern', 'ingredients' => [],
            'nutrients' => [['code' => 'sugar', 'value' => '4.2', 'qualifier' => 'maximum', 'unit' => '%', 'basis' => 'unknown', 'source' => 'Suiker maximaal 4,2%']]], $overrides);
    }

    public function test_ingredient_drafts_publish_and_keep_previous_publication_until_approved(): void
    {
        $this->post('/admin/feed-scanner/ingredients', $this->ingredient())->assertRedirect()->assertSessionHasNoErrors();
        $ingredient = Ingredient::where('name', 'Lijnzaad')->sole();
        $first = IngredientRevision::where('ingredient_id', $ingredient->id)->sole();
        $this->assertSame('draft', $ingredient->catalog_status);
        $this->assertNull($ingredient->description);
        $this->post("/admin/feed-scanner/ingredients/{$ingredient->id}/publish", ['version_id' => $first->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->put("/admin/feed-scanner/ingredients/{$ingredient->id}", $this->ingredient(['explanation' => 'Nieuwe uitleg', 'expected_number' => 1]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Herkenbaar ingrediënt.', $ingredient->fresh()->description);
        $this->assertSame('Herkenbaar ingrediënt.', $first->fresh()->data['explanation']);
        $this->assertSame(2, IngredientRevision::where('ingredient_id', $ingredient->id)->count());
        $this->put("/admin/feed-scanner/ingredients/{$ingredient->id}", $this->ingredient(['expected_number' => 1]))->assertSessionHasErrors('expected_number');
        $this->put("/admin/ingredients/{$ingredient->id}", ['name' => 'Bypass'])->assertStatus(409);
    }

    public function test_ambiguous_exact_synonym_cannot_be_published_but_related_term_can(): void
    {
        $this->post('/admin/feed-scanner/ingredients', $this->ingredient());
        $one = Ingredient::where('name', 'Lijnzaad')->sole();
        $this->post("/admin/feed-scanner/ingredients/{$one->id}/publish", ['version_id' => IngredientRevision::where('ingredient_id', $one->id)->sole()->id]);
        $this->post('/admin/feed-scanner/ingredients', $this->ingredient(['name' => 'Ander ingrediënt']));
        $two = Ingredient::where('name', 'Ander ingrediënt')->sole();
        $revision = IngredientRevision::where('ingredient_id', $two->id)->sole();
        $this->post("/admin/feed-scanner/ingredients/{$two->id}/publish", ['version_id' => $revision->id])->assertSessionHasErrors('terms');
        $this->put("/admin/feed-scanner/ingredients/{$two->id}", $this->ingredient(['name' => 'Ander ingrediënt', 'expected_number' => 1,
            'terms' => [['term' => 'Linseed', 'language' => 'en', 'kind' => 'related']]]))->assertSessionHasNoErrors();
        $next = IngredientRevision::where('ingredient_id', $two->id)->orderByDesc('number')->first();
        $this->post("/admin/feed-scanner/ingredients/{$two->id}/publish", ['version_id' => $next->id])->assertSessionHasNoErrors();
    }

    public function test_product_review_keeps_versions_sources_unknown_basis_and_ordered_projection(): void
    {
        $ingredient = Ingredient::create(['name' => 'Lijnzaad']);
        $row = ['ingredient_id' => $ingredient->id, 'label' => 'Linseed', 'amount' => '12%', 'match_status' => 'confirmed', 'added_selenium' => 'unknown', 'selenium_form' => '', 'evidence' => ''];
        $this->post('/admin/feed-scanner/products', $this->product(['ingredients' => [$row]]))->assertSessionHasNoErrors();
        $product = Product::where('name', 'Voorbeeldvoer')->sole();
        $first = ProductVersion::where('product_id', $product->id)->sole();
        $this->assertCount(0, $product->ingredients);
        $this->assertSame('unknown', $first->data['nutrients'][0]['basis']);
        $this->assertSame('maximum', $first->data['nutrients'][0]['qualifier']);
        $this->post("/admin/feed-scanner/products/{$product->id}/review", ['version_id' => $first->id, 'decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('12%', $product->fresh()->ingredients->sole()->pivot->amount);
        $this->put("/admin/feed-scanner/products/{$product->id}", $this->product(['name' => 'Nieuw concept', 'category' => 'balancer', 'expected_number' => 1]))->assertSessionHasNoErrors();
        $this->assertSame('Voorbeeldvoer', $product->fresh()->name);
        $this->assertSame($first->id, $product->fresh()->published_version_id);
        $next = ProductVersion::where('product_id', $product->id)->orderByDesc('number')->first();
        $this->assertSame('balancer', DB::table('feed_assessment_profiles')->where('id', $next->profile_id)->value('code'));
        $this->assertSame('Voorbeeldvoer', $first->fresh()->data['name']);
        $this->put("/admin/products/{$product->id}", ['brand' => 'Bypass', 'name' => 'Bypass'])->assertStatus(409);
        $this->post("/admin/feed-scanner/products/{$product->id}/archive")->assertSessionHasNoErrors();
        $this->assertSame('archived', $product->fresh()->catalog_status);
        $this->assertSame(2, ProductVersion::where('product_id', $product->id)->count());
    }

    public function test_analysis_validation_and_selenium_evidence_do_not_invent_missing_values(): void
    {
        $this->post('/admin/feed-scanner/products', $this->product(['nutrients' => [
            ['code' => 'sugar', 'value' => 101, 'unit' => '%', 'basis' => 'product'],
        ]]))->assertSessionHasErrors('nutrients.0.value');
        $this->post('/admin/feed-scanner/products', $this->product(['ingredients' => [
            ['ingredient_id' => null, 'label' => 'Selenium', 'match_status' => 'unknown', 'added_selenium' => 'confirmed'],
        ]]))->assertSessionHasErrors('ingredients.0.evidence');
        $this->post('/admin/feed-scanner/products', $this->product(['approve' => true, 'nutrients' => [
            ['code' => 'selenium_total', 'value' => 0.2, 'unit' => 'mg/kg', 'basis' => 'product'],
        ]]))->assertSessionHasNoErrors();
        $version = ProductVersion::where('status', 'approved')->sole();
        $this->assertSame([], $version->data['ingredients']);
        $this->assertArrayNotHasKey('score', $version->data);
    }

    public function test_private_label_upload_is_versioned_retained_and_protected(): void
    {
        Storage::fake('local');
        $this->post('/admin/feed-scanner/products', $this->product());
        $product = Product::where('name', 'Voorbeeldvoer')->sole();
        $version = ProductVersion::where('product_id', $product->id)->sole();
        $this->post("/admin/feed-scanner/versions/{$version->id}/attachments", ['purpose' => 'analysis', 'file' => UploadedFile::fake()->create('etiket.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
        $file = FeedLabelAttachment::sole();
        Storage::disk('local')->assertExists($file->path);
        $this->get("/admin/feed-scanner/attachments/{$file->id}")->assertOk();
        $this->put("/admin/feed-scanner/products/{$product->id}", $this->product(['expected_number' => 1]))->assertSessionHasNoErrors();
        $this->assertSame(2, FeedLabelAttachment::count());
        $this->assertSame(1, FeedLabelAttachment::distinct()->count('path'));
        $this->post("/admin/feed-scanner/versions/{$version->id}/attachments", ['purpose' => 'analysis', 'file' => UploadedFile::fake()->create('oud.pdf', 20, 'application/pdf')])->assertStatus(409);
        $this->admin->update(['role' => 'support']);
        $this->get("/admin/feed-scanner/attachments/{$file->id}")->assertForbidden();
    }

    public function test_policy_versions_preserve_published_knowledge_snapshots_without_scores(): void
    {
        $card = FeedKnowledgeCard::create(['title' => 'Suiker + zetmeel', 'explanation' => 'Samen beoordelen', 'status' => 'published']);
        $profile = DB::table('feed_assessment_profiles')->where('code', 'standard')->sole();
        $original = FeedRuleVersion::where('profile_id', $profile->id)->sole();
        $payload = collect($original->data)->only(['principles', 'explanation', 'sugar_starch_challenged', 'sugar_starch_healthy', 'organic_selenium_cap', 'unknown_added_selenium_cap', 'exceptions'])->all();
        $this->post("/admin/feed-scanner/profiles/{$profile->id}/rules", $payload + ['expected_number' => 1])->assertSessionHasNoErrors();
        $latest = FeedRuleVersion::where('profile_id', $profile->id)->orderByDesc('number')->first();
        $card->update(['explanation' => 'Nieuwe uitleg']);
        $this->assertSame('Samen beoordelen', $latest->fresh()->data['knowledge_snapshot'][0]['explanation']);
        $this->assertSame('uncalibrated', $latest->calibration_status);
        $this->assertArrayNotHasKey('weights', $latest->data);
        $this->assertEquals(5.5, $latest->data['unknown_added_selenium_cap']);
        $this->assertSame('draft', $latest->status);
        $this->post("/admin/feed-scanner/profiles/{$profile->id}/rules", $payload + ['expected_number' => 1])->assertSessionHasErrors('expected_number');
    }

    public function test_content_editor_can_save_concepts_but_cannot_publish_or_approve(): void
    {
        $this->admin->update(['role' => 'content_editor']);
        $this->post('/admin/feed-scanner/ingredients', $this->ingredient())->assertSessionHasNoErrors();
        $ingredient = Ingredient::where('name', 'Lijnzaad')->sole();
        $this->post("/admin/feed-scanner/ingredients/{$ingredient->id}/publish", ['version_id' => IngredientRevision::where('ingredient_id', $ingredient->id)->sole()->id])->assertForbidden();
        $this->post('/admin/feed-scanner/products', $this->product(['approve' => true]))->assertForbidden();
        $this->post('/admin/feed-scanner/cards', ['title' => 'Test', 'status' => 'published'])->assertForbidden();
        $this->get('/admin/feed-scanner/ingredients')->assertOk()->assertInertia(fn (Assert $page) => $page->component('FeedScanner/Ingredients')->where('canPublish', false));
        $this->admin->update(['role' => 'support']);
        $this->get('/admin/feed-scanner/products')->assertForbidden();
    }

    public function test_versions_refuse_in_place_changes_and_public_sync_excludes_internal_columns(): void
    {
        $profile = FeedRuleVersion::firstOrFail();
        try {
            $profile->update(['data' => ['tampered' => true]]);
            $this->fail('Version data must be immutable.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('nieuwe versie', $e->getMessage());
        }
        $yaml = file_get_contents(base_path('powersync/sync_rules.yaml'));
        $this->assertStringNotContainsString('SELECT * FROM products ', $yaml);
        $this->assertStringNotContainsString('SELECT * FROM ingredients ', $yaml);
        $this->assertStringContainsString("catalog_status = 'legacy' OR catalog_status = 'published'", $yaml);
    }

    public function test_backfill_preserves_original_ids_order_and_free_amounts_without_duplication(): void
    {
        $one = Ingredient::create(['name' => 'Original ingredient', 'description' => 'Original explanation']);
        $two = Ingredient::create(['name' => 'Second ingredient']);
        $product = Product::create(['brand' => 'Original brand', 'name' => 'Original product', 'category' => 'feed']);
        $product->ingredients()->attach([$one->id => ['order' => 3, 'amount' => 'ongeveer 12%'], $two->id => ['order' => 1, 'amount' => '250 mg']]);
        $migration = require database_path('migrations/2026_10_22_100000_create_feed_catalog_management.php');
        $migration->backfillLegacy();
        $migration->backfillLegacy();
        $this->assertSame(2, IngredientRevision::count());
        $version = ProductVersion::sole();
        $this->assertSame($product->id, $version->product_id);
        $this->assertSame([$two->id, $one->id], array_column($version->data['ingredients'], 'ingredient_id'));
        $this->assertSame('ongeveer 12%', $version->data['ingredients'][1]['amount']);
        $this->assertSame([], $version->data['nutrients']);
        $this->assertSame('legacy', $version->status);
        $this->assertNull($product->fresh()->published_version_id);
        $this->assertCount(2, $product->fresh()->ingredients);
        $this->put("/admin/feed-scanner/products/{$product->id}", $this->product(['expected_number' => 1]))->assertSessionHasNoErrors();
        $this->assertSame('legacy', $product->fresh()->catalog_status);
        $this->assertSame('Original product', $product->fresh()->name);
        $this->put("/admin/products/{$product->id}", ['brand' => 'Bypass', 'name' => 'Bypass'])->assertStatus(409);
        $this->put("/admin/feed-scanner/ingredients/{$one->id}", $this->ingredient(['expected_number' => 1]))->assertSessionHasNoErrors();
        $this->assertSame('legacy', $one->fresh()->catalog_status);
        $this->assertSame('Original explanation', $one->fresh()->description);
        $this->put("/admin/ingredients/{$one->id}", ['name' => 'Bypass'])->assertStatus(409);
    }

    public function test_review_queue_and_category_filter_use_the_current_version(): void
    {
        $this->post('/admin/feed-scanner/products', $this->product(['category' => 'balancer', 'approve' => true]));
        $product = Product::where('name', 'Voorbeeldvoer')->sole();
        $this->put("/admin/feed-scanner/products/{$product->id}", $this->product(['expected_number' => 1, 'approve' => true]))->assertSessionHasNoErrors();
        $this->get('/admin/feed-scanner/products?category=balancer')->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
        $this->get('/admin/feed-scanner/products?category=complementary')->assertInertia(fn (Assert $page) => $page->where('products.total', 1));
        Product::create(['brand' => 'Legacy brand', 'name' => 'Needs first review']);
        $this->get('/admin/feed-scanner/products?review=1')->assertInertia(fn (Assert $page) => $page->where('products.total', 1)->where('reviewCount', 1));
    }
}
