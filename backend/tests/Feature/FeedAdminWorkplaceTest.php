<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\FeedKnowledgeCard;
use App\Models\FeedRuleVersion;
use App\Models\Ingredient;
use App\Models\IngredientRevision;
use App\Models\Product;
use App\Models\ScanResult;
use App\Models\User;
use App\Support\FeedCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FeedAdminWorkplaceTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AdminUser::create(['name' => 'Beheerder', 'email' => 'workplace@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $this->actingAs($this->admin, 'admin');
    }

    private function product(string $name, string $status = 'draft'): Product
    {
        $product = Product::create(['brand' => 'Test', 'name' => $name]);
        $product->forceFill(['catalog_status' => 'draft', 'needs_review' => true])->save();
        $version = DB::transaction(fn () => FeedCatalog::productDraft($product, ['name' => $name, 'brand' => 'Test', 'category' => 'complementary', 'ingredients' => [], 'nutrients' => []], $this->admin->id));
        if ($status !== 'draft') {
            $version->update(['status' => $status]);
            $product->forceFill(['needs_review' => $status === 'needs_info', 'catalog_status' => $status === 'approved' ? 'published' : 'draft'])->save();
        }

        return $product;
    }

    public function test_dashboard_uses_real_counts_and_amsterdam_month_boundaries(): void
    {
        $this->get('/admin/feed-scanner')->assertInertia(fn (Assert $p) => $p->component('FeedScanner/Overview')->where('statistics.products', 0)->where('statistics.review', 0)->where('statistics.published_cards', 0)->where('statistics.month_scans', 0)->has('tasks', 0));
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'Europe/Amsterdam'));
        $this->product('Wacht op controle');
        $this->product('Informatie nodig', 'needs_info');
        $this->product('Goedgekeurd', 'approved');
        Ingredient::create(['name' => 'Gepubliceerde kaart'])->forceFill(['catalog_status' => 'published'])->save();
        FeedKnowledgeCard::create(['title' => 'Voedingskennis', 'status' => 'published']);
        $user = User::factory()->create();
        foreach (['2026-09-30 21:59:59', '2026-09-30 22:00:00', '2026-10-31 22:59:59', '2026-10-31 23:00:00'] as $date) {
            ScanResult::create(['user_id' => $user->id, 'scanned_at' => $date, 'score' => 62, 'rating' => 'Matig']);
        }
        $this->get('/admin/feed-scanner')->assertInertia(fn (Assert $p) => $p->where('statistics.products', 3)->where('statistics.review', 1)->where('statistics.published_cards', 2)->where('statistics.month_scans', 2)->has('pendingProducts', 1));
    }

    public function test_product_filters_follow_latest_review_status_and_keep_search_and_deep_links(): void
    {
        $draft = $this->product('Timothee');
        $info = $this->product('Hooi', 'needs_info');
        $approved = $this->product('Balancer', 'approved');
        foreach (['review' => $draft, 'needs_info' => $info, 'approved' => $approved] as $status => $product) {
            $this->get('/admin/feed-scanner/products?status='.$status)->assertInertia(fn (Assert $p) => $p->where('products.total', 1)->where('products.data.0.id', $product->id));
        }
        $this->get('/admin/feed-scanner/products?review=1')->assertInertia(fn (Assert $p) => $p->where('products.total', 1));
        $this->get('/admin/feed-scanner/products?status=review&q=does-not-exist')->assertInertia(fn (Assert $p) => $p->where('products.total', 0));
        $this->get('/admin/feed-scanner/products?open='.$info->id)->assertInertia(fn (Assert $p) => $p->where('filters.open', $info->id)->where('products.total', 1)->where('products.data.0.id', $info->id));
    }

    public function test_knowledge_tabs_reuse_existing_cards_and_settings_report_zero_without_changing_rules(): void
    {
        FeedKnowledgeCard::create(['title' => 'Selenium uitleg', 'status' => 'draft']);
        FeedKnowledgeCard::create(['title' => 'Andere uitleg', 'status' => 'published']);
        $before = FeedRuleVersion::orderBy('id')->get()->toJson();
        $this->get('/admin/feed-scanner/knowledge')->assertInertia(fn (Assert $p) => $p->component('FeedScanner/Ingredients'));
        $this->get('/admin/feed-scanner/knowledge?tab=nutrition&q=Selenium&status=draft')->assertInertia(fn (Assert $p) => $p->component('FeedScanner/Rules')->where('mode', 'knowledge')->has('cards', 1)->where('cards.0.title', 'Selenium uitleg'));
        $this->get('/admin/feed-scanner/settings')->assertInertia(fn (Assert $p) => $p->component('FeedScanner/Rules')->where('scoreConflicts', fn ($items) => count($items) >= 2 && collect($items)->every(fn ($item) => str_contains($item['rule'], '0/10'))));
        $this->assertSame($before, FeedRuleVersion::orderBy('id')->get()->toJson());
    }

    public function test_remove_and_restore_keep_ingredient_links_revisions_and_publication_history(): void
    {
        $ingredient = Ingredient::create(['name' => 'Testzaad']);
        $version = DB::transaction(fn () => FeedCatalog::ingredientDraft($ingredient, ['name' => 'Testzaad', 'explanation' => 'Bewaarde uitleg', 'terms' => []], $this->admin->id));
        $product = $this->product('Gekoppeld product');
        $product->ingredients()->attach($ingredient->id, ['order' => 1]);
        $before = $version->fresh()->toJson();
        $this->delete('/admin/feed-scanner/ingredients/'.$ingredient->id)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('removed', $ingredient->fresh()->catalog_status);
        $this->assertSame($before, $version->fresh()->toJson());
        $this->assertSame(1, IngredientRevision::where('ingredient_id', $ingredient->id)->count());
        $this->assertCount(1, $product->fresh()->ingredients);
        $this->get('/admin/feed-scanner/knowledge')->assertInertia(fn (Assert $p) => $p->where('ingredients.total', 0));
        $this->get('/admin/feed-scanner/knowledge?status=removed')->assertInertia(fn (Assert $p) => $p->where('ingredients.total', 1));
        $this->get('/admin/feed-scanner/products')->assertInertia(fn (Assert $p) => $p->has('ingredientOptions', 0));
        $this->post('/admin/feed-scanner/ingredients/'.$ingredient->id.'/publish', ['version_id' => $version->id])->assertStatus(409);
        $this->post('/admin/feed-scanner/ingredients/'.$ingredient->id.'/archive')->assertStatus(409);
        $this->post('/admin/feed-scanner/ingredients/'.$ingredient->id.'/restore')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('archived', $ingredient->fresh()->catalog_status);
        $this->assertSame($before, $version->fresh()->toJson());
    }

    public function test_permissions_are_preserved_for_editors_and_support(): void
    {
        $ingredient = Ingredient::create(['name' => 'Alleen beheerder mag verwijderen']);
        $editor = AdminUser::create(['name' => 'Redacteur', 'email' => 'workplace-editor@example.com', 'password' => 'password', 'role' => 'content_editor', 'active' => true]);
        $this->actingAs($editor, 'admin');
        foreach (['', '/knowledge', '/settings', '/products'] as $path) {
            $this->get('/admin/feed-scanner'.$path)->assertOk();
        }
        $this->delete('/admin/feed-scanner/ingredients/'.$ingredient->id)->assertForbidden();
        $this->post('/admin/feed-scanner/ingredients/'.$ingredient->id.'/restore')->assertForbidden();
        $editor->update(['role' => 'support']);
        foreach (['', '/knowledge', '/settings', '/products'] as $path) {
            $this->get('/admin/feed-scanner'.$path)->assertForbidden();
        }
    }
}
