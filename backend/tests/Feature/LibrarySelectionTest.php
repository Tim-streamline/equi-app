<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\LibraryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LibrarySelectionTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        $user = User::factory()->create();
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($user->id)
        {
            public function __construct(private string $id) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->id);

                return $next($request);
            }
        });

        return $user;
    }

    private function pair(): array
    {
        return array_map(fn ($slug) => LibraryItem::create([
            'slug' => $slug, 'title' => $slug, 'format' => 'article',
            'published_at' => now()->subDay(), 'credit_cost' => 2, 'body' => 'Private lesson',
        ]), config('library.selections.hay-analysis'));
    }

    public function test_selection_returns_only_the_fixed_pair_in_order_with_existing_access(): void
    {
        $user = $this->customer();
        [$first, $second] = $this->pair();
        $second->update(['format' => 'video']);
        LibraryItem::create(['title' => 'Another hay analysis', 'slug' => 'unrelated-hay-analysis', 'format' => 'article', 'published_at' => now()]);
        DB::table('library_unlocks')->insert(['user_id' => $user->id, 'item_id' => $second->id]);
        $response = $this->getJson('/api/library/selections/hay-analysis')->assertOk()
            ->assertJsonCount(2, 'items')->assertJsonPath('items.0.id', $first->id)
            ->assertJsonPath('items.1.id', $second->id)->assertJsonPath('items.1.format', 'video')
            ->assertJsonPath('access.unlockedIds.0', $second->id)->assertJsonPath('access.hasPlus', false);
        $this->assertArrayNotHasKey('body', $response->json('items.0'));
        $this->getJson('/api/library/'.$first->id)->assertJsonPath('canRead', false)->assertJsonPath('body', null);
        $this->getJson('/api/library/'.$second->id)->assertJsonPath('canRead', true);
        $this->getJson('/api/library/selections/unknown')->assertNotFound();
    }

    public function test_missing_draft_or_future_item_never_returns_a_partial_selection(): void
    {
        $this->customer();
        $this->getJson('/api/library/selections/hay-analysis')->assertStatus(503);
        [$first] = $this->pair();
        foreach ([null, now()->addDay()] as $publication) {
            $first->update(['published_at' => $publication]);
            $this->getJson('/api/library/selections/hay-analysis')->assertStatus(503);
        }
    }

    public function test_bookmark_listing_is_private_and_tracks_save_and_remove(): void
    {
        $owner = $this->customer();
        [$item] = $this->pair();
        $this->getJson('/api/library/bookmarks')->assertOk()->assertJsonPath('itemIds', []);
        $this->putJson('/api/library/'.$item->id.'/bookmark')->assertOk();
        $this->getJson('/api/library/bookmarks')->assertJsonPath('itemIds', [$item->id]);
        $this->deleteJson('/api/library/'.$item->id.'/bookmark')->assertOk();
        $this->getJson('/api/library/bookmarks')->assertJsonPath('itemIds', []);
        DB::table('library_bookmarks')->insert(['id' => (string) Str::uuid(), 'user_id' => $owner->id, 'item_id' => $item->id]);
        $this->customer();
        $this->getJson('/api/library/bookmarks')->assertJsonPath('itemIds', []);
    }
}
