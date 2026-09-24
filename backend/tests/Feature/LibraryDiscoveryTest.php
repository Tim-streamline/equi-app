<?php
namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\{AdminUser, LibraryCategory, LibraryItem, Plan, Subscription, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LibraryDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $title, array $data = []): LibraryItem
    {
        return LibraryItem::create($data + ['title' => $title, 'slug' => Str::slug($title), 'format' => 'article', 'published_at' => now()->subDay()]);
    }
    private function customer(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($user->id) {
            public function __construct(private string $id) {}
            public function handle($request, $next) {
                $request->attributes->set('powersync_user_id', $this->id);
                return $next($request);
            }
        });
        return $user;
    }
    private function admin(): void
    {
        $this->actingAs(AdminUser::create(['name' => 'Editor', 'email' => 'discovery@example.test', 'password' => 'password', 'role' => 'content_editor', 'active' => true]), 'admin');
    }
    public function test_bookmarks_persist_idempotently_are_private_and_can_be_removed(): void
    {
        $item = $this->item('Lesson'); $owner = $this->customer();
        $url = '/api/library/'.$item->id.'/bookmark';
        $this->getJson($url)->assertOk()->assertJsonPath('bookmarked', false);
        $this->putJson($url)->assertOk()->assertJsonPath('bookmarked', true);
        $this->putJson($url)->assertOk()->assertJsonPath('bookmarked', true);
        $this->assertDatabaseCount('library_bookmarks', 1);
        $this->customer();
        $this->getJson($url)->assertJsonPath('bookmarked', false);
        $this->deleteJson($url)->assertJsonPath('bookmarked', false);
        $this->assertDatabaseCount('library_bookmarks', 1);
        $this->customer($owner);
        $this->getJson($url)->assertJsonPath('bookmarked', true);
        $item->update(['published_at' => null]);
        $this->putJson($url)->assertNotFound();
        $this->deleteJson($url)->assertOk()->assertJsonPath('bookmarked', false);
        $this->assertDatabaseCount('library_bookmarks', 0);
    }
    public function test_related_items_prioritize_pins_then_category_overlap_then_text_and_exclude_unpublished(): void
    {
        $this->customer();
        $a = LibraryCategory::create(['label' => 'Darmen', 'slug' => 'darmen']);
        $b = LibraryCategory::create(['label' => 'Voeding', 'slug' => 'voeding']);
        $source = $this->item('Darmen kruiden advies'); $source->categories()->sync([$a->id, $b->id]);
        $partial = $this->item('Darmen kruiden gedeeltelijk'); $partial->categories()->sync([$a->id]);
        $weak = $this->item('Andere voeding'); $weak->categories()->sync([$a->id, $b->id]);
        $strong = $this->item('Darmen kruiden uitleg'); $strong->categories()->sync([$a->id, $b->id]);
        $pin = $this->item('Handmatig');
        $draft = $this->item('Concept', ['published_at' => null]);
        $future = $this->item('Toekomst', ['published_at' => now()->addDay()]);
        $source->update(['featured_suggestion_ids' => [$draft->id, $pin->id, $future->id, $source->id]]);
        $response = $this->getJson('/api/library/'.$source->id.'/related')->assertOk()->assertJsonCount(4, 'items');
        $this->assertSame([$pin->id, $strong->id, $weak->id, $partial->id], array_column($response->json('items'), 'id'));
        $this->assertArrayNotHasKey('body', $response->json('items.0'));
        $this->getJson('/api/library/'.$draft->id.'/related')->assertNotFound();
    }
    public function test_suggestion_validation_and_ordered_persistence(): void
    {
        $this->admin(); $source = $this->item('Source'); $a = $this->item('First'); $b = $this->item('Second');
        $url = '/admin/library/'.$source->id; $base = ['title' => 'Source', 'format' => 'article'];
        $this->put($url, $base + ['featured_suggestion_ids' => [$b->id, $a->id]])->assertSessionHasNoErrors();
        $this->assertSame([$b->id, $a->id], $source->fresh()->featured_suggestion_ids);
        foreach ([[$source->id], [$a->id, $a->id], [(string) Str::uuid()]] as $ids) {
            $this->put($url, $base + ['featured_suggestion_ids' => $ids])->assertSessionHasErrors('featured_suggestion_ids.0');
        }
        $this->put($url, $base + ['featured_suggestion_ids' => array_fill(0, 5, $a->id)])->assertSessionHasErrors('featured_suggestion_ids');
        $this->put($url, $base + ['featured_suggestion_ids' => []])->assertSessionHasNoErrors();
        $this->assertSame([], $source->fresh()->featured_suggestion_ids);
    }
    public function test_multiple_category_filters_combine_with_search_and_pin_settings(): void
    {
        $this->admin();
        $a = LibraryCategory::create(['label' => 'A', 'slug' => 'a']); $b = LibraryCategory::create(['label' => 'B', 'slug' => 'b']);
        $this->put('/admin/library-categories/'.$b->id, ['label' => 'B', 'is_quick_filter' => true, 'order' => 2])->assertSessionHasNoErrors();
        $this->assertTrue($b->fresh()->is_quick_filter);
        $one = $this->item('Horse one'); $one->categories()->sync([$a->id, $b->id]);
        $two = $this->item('Horse two'); $two->categories()->sync([$b->id]); $this->item('Horse excluded');
        $this->get('/admin/library?'.http_build_query(['q' => 'Horse', 'category_ids' => [$a->id, $b->id]]))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 2)->where('categories.0.id', $b->id)->where('categories.0.items_count', 2));
        $this->get('/admin/library?'.http_build_query(['q' => 'two', 'category_ids' => [$a->id]]))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 0));
        $this->get('/admin/library')->assertInertia(fn (Assert $page) => $page->has('items.data', 3));
    }
    public function test_access_metadata_preserves_plus_and_existing_credit_unlocks(): void
    {
        $user = $this->customer(); $item = $this->item('Paid', ['credit_cost' => 2, 'is_plus' => true]);
        $this->getJson('/api/library/access')->assertJsonPath('hasPlus', false)->assertJsonPath('unlockedIds', []);
        DB::table('library_unlocks')->insert(['user_id' => $user->id, 'item_id' => $item->id]);
        $this->getJson('/api/library/access')->assertJsonPath('unlockedIds.0', $item->id);
        $plan = Plan::create(['slug' => 'plus', 'label' => 'Plus', 'name' => 'Plus', 'price_cents' => 900, 'interval' => 'monthly']);
        $subscription = Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active', 'price_cents' => 900, 'interval' => 'monthly']);
        $this->getJson('/api/library/access')->assertJsonPath('hasPlus', true);
        $subscription->update(['cancelled_at' => now()->subDay()]);
        $this->getJson('/api/library/access')->assertJsonPath('hasPlus', false);
    }
    public function test_credit_unlock_is_confirmed_idempotent_and_hides_locked_body(): void
    {
        $user = $this->customer();
        $item = $this->item('Credits', ['credit_cost' => 2, 'is_plus' => false, 'body' => 'Paid lesson']);
        DB::table('library_credit_balances')->insert(['user_id' => $user->id, 'balance' => 3]);
        $url = '/api/library/'.$item->id;
        $this->getJson($url)->assertJsonPath('canRead', false)->assertJsonPath('body', null);
        $this->postJson($url.'/unlock', ['credits' => 1])->assertStatus(409);
        $this->assertDatabaseHas('library_credit_balances', ['user_id' => $user->id, 'balance' => 3]);
        $this->postJson($url.'/unlock', ['credits' => 2])->assertOk()->assertJsonPath('body', 'Paid lesson');
        $this->postJson($url.'/unlock', ['credits' => 2])->assertOk()->assertJsonPath('canRead', true);
        $this->assertDatabaseHas('library_credit_balances', ['user_id' => $user->id, 'balance' => 1]);
        $this->assertDatabaseCount('library_unlocks', 1);
        $other = $this->item('Too expensive', ['credit_cost' => 2, 'is_plus' => false]);
        $this->postJson('/api/library/'.$other->id.'/unlock', ['credits' => 2])->assertStatus(422);
        $other->update(['is_plus' => true]);
        $this->postJson('/api/library/'.$other->id.'/unlock', ['credits' => 2])->assertForbidden();
        $this->assertDatabaseCount('library_unlocks', 1);
        $this->customer();
        $this->getJson($url)->assertJsonPath('canRead', false)->assertJsonPath('body', null);
    }

}
