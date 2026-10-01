<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\LibraryItem;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CreditLedger;
use App\Support\LibraryDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LibrarySyncTest extends TestCase
{
    use RefreshDatabase;

    private function item(array $attributes = []): LibraryItem
    {
        return LibraryItem::create($attributes + ['slug' => (string) Str::uuid(), 'title' => 'Lesson', 'format' => 'article', 'body' => 'Protected content', 'published_at' => now()->subDay()]);
    }

    private function customer(User $user): void
    {
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($user->id)
        {
            public function __construct(private string $id) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->id);

                return $next($request);
            }
        });
    }

    private function plus(User $user, array $attributes = []): Subscription
    {
        $plan = Plan::firstOrCreate(['slug' => 'plus'], ['name' => 'Plus', 'price_cents' => 100, 'interval' => 'month']);

        return Subscription::create($attributes + ['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active', 'price_cents' => 100, 'interval' => 'month']);
    }

    public function test_access_projection_matches_api_for_free_paid_plus_and_permanent_unlocks(): void
    {
        $basic = User::factory()->create();
        $plus = User::factory()->create();
        $this->plus($plus);
        $free = $this->item();
        $paid = $this->item(['credit_cost' => 2]);
        $premium = $this->item(['is_plus' => true]);
        $draft = $this->item(['published_at' => null]);
        $future = $this->item(['published_at' => now()->addDay()]);
        DB::table('library_unlocks')->insert(['user_id' => $basic->id, 'item_id' => $paid->id]);
        $discovery = app(LibraryDiscovery::class);
        foreach ([$basic, $plus] as $user) {
            $access = $discovery->access($user);
            foreach ([$free, $paid, $premium, $draft, $future] as $item) {
                $expected = $item->published_at && $item->published_at <= now() && $discovery->canRead($item, $access);
                $this->assertSame((bool) $expected, DB::table('library_item_access')->where('user_id', $user->id)->where('item_id', $item->id)->exists());
            }
        }
        $this->assertDatabaseHas('library_item_access', ['user_id' => $basic->id, 'item_id' => $paid->id, 'reason' => 'unlocked', 'expires_at' => null]);
        $this->assertDatabaseHas('library_item_access', ['user_id' => $plus->id, 'item_id' => $premium->id, 'reason' => 'plus']);
    }

    public function test_real_credit_purchase_grants_access_atomically_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $item = $this->item(['credit_cost' => 2]);
        $this->customer($user);
        app(CreditLedger::class)->grant($user, 5, 'test');
        $this->assertDatabaseMissing('library_item_access', ['item_id' => $item->id]);
        $this->postJson('/api/library/'.$item->id.'/unlock', ['credits' => 2])->assertOk()->assertJsonPath('canRead', true);
        $this->postJson('/api/library/'.$item->id.'/unlock', ['credits' => 2])->assertOk();
        $this->assertDatabaseHas('library_item_access', ['user_id' => $user->id, 'item_id' => $item->id, 'reason' => 'unlocked']);
        $this->assertSame(3, app(CreditLedger::class)->summary($user)['balance']);
        $this->assertDatabaseCount('library_item_access', 1);
    }

    public function test_bulk_changes_revoke_access_and_preserve_permanent_unlocks(): void
    {
        $user = User::factory()->create();
        $item = $this->item(['is_plus' => true]);
        $subscription = $this->plus($user);
        $this->assertDatabaseHas('library_item_access', ['item_id' => $item->id]);
        DB::table('subscriptions')->where('id', $subscription->id)->update(['status' => 'cancelled']);
        $this->assertDatabaseMissing('library_item_access', ['item_id' => $item->id]);
        DB::table('library_unlocks')->insert(['user_id' => $user->id, 'item_id' => $item->id]);
        $this->assertDatabaseHas('library_item_access', ['item_id' => $item->id, 'reason' => 'unlocked']);
        $item->update(['published_at' => null]);
        $this->assertDatabaseCount('library_item_access', 0);
        $item->update(['published_at' => now()->subDay()]);
        $user->update(['disabled_at' => now()]);
        $this->assertDatabaseCount('library_item_access', 0);
        $user->update(['disabled_at' => null]);
        $this->assertDatabaseCount('library_item_access', 1);
        DB::table('library_unlocks')->where('user_id', $user->id)->delete();
        $this->assertDatabaseCount('library_item_access', 0);
    }

    public function test_scheduled_reconciliation_handles_expiry_and_future_publication_without_source_writes(): void
    {
        $user = User::factory()->create();
        $premium = $this->item(['is_plus' => true]);
        $future = $this->item(['published_at' => now()->addMinutes(2)]);
        $expiry = now()->addMinute()->startOfSecond();
        $this->plus($user, ['paid_through' => $expiry]);
        $this->assertDatabaseHas('library_item_access', ['item_id' => $premium->id, 'expires_at' => $expiry->format('Y-m-d H:i:s')]);
        $this->travel(3)->minutes();
        $this->artisan('library:refresh-access')->assertSuccessful();
        $this->assertDatabaseMissing('library_item_access', ['item_id' => $premium->id]);
        $this->assertDatabaseHas('library_item_access', ['item_id' => $future->id]);
    }

    public function test_multiple_plus_subscriptions_use_last_valid_expiry_and_unlimited_access(): void
    {
        $user = User::factory()->create();
        $item = $this->item(['is_plus' => true]);
        $first = $this->plus($user, ['paid_through' => now()->addDays(2)]);
        $second = $this->plus($user, ['paid_through' => now()->addDays(4), 'cancelled_at' => now()->addDays(3)->toDateString()]);
        $this->assertDatabaseHas('library_item_access', ['item_id' => $item->id, 'expires_at' => now()->addDays(3)->startOfDay()->format('Y-m-d H:i:s')]);
        $unlimited = $this->plus($user);
        $this->assertDatabaseHas('library_item_access', ['item_id' => $item->id, 'expires_at' => null]);
        $unlimited->delete();
        $second->delete();
        $first->update(['ended_at' => now()]);
        $this->assertDatabaseCount('library_item_access', 0);
    }

    public function test_content_projection_tracks_body_chapters_and_safe_attachment_metadata(): void
    {
        $item = $this->item();
        $chapter = $item->chapters()->create(['title' => 'Second', 'order' => 2, 'start_label' => '01:00']);
        $first = $item->chapters()->create(['title' => 'First', 'order' => 1, 'start_label' => '00:00']);
        $attachment = $item->attachments()->create(['title' => 'Worksheet', 'name' => 'worksheet.pdf', 'path' => 'private/secret.pdf', 'size_bytes' => 10]);
        $content = DB::table('library_contents')->where('id', $item->id)->first();
        $this->assertSame([$first->id, $chapter->id], array_column(json_decode($content->chapters, true), 'id'));
        $this->assertSame([['id' => $attachment->id, 'name' => 'worksheet.pdf', 'title' => 'Worksheet']], json_decode($content->attachments, true));
        $item->update(['body' => 'Updated body']);
        $chapter->update(['title' => 'Changed']);
        $first->delete();
        $attachment->delete();
        $content = DB::table('library_contents')->where('id', $item->id)->first();
        $this->assertSame('Updated body', $content->body);
        $this->assertSame('Changed', json_decode($content->chapters, true)[0]['title']);
        $this->assertSame([], json_decode($content->attachments, true));
        $item->delete();
        $this->assertDatabaseCount('library_contents', 0);
    }

    public function test_grants_rollback_with_source_transaction_and_clients_cannot_write_projections(): void
    {
        $user = User::factory()->create();
        $item = $this->item(['credit_cost' => 2]);
        DB::beginTransaction();
        DB::table('library_unlocks')->insert(['user_id' => $user->id, 'item_id' => $item->id]);
        $this->assertDatabaseCount('library_item_access', 1);
        DB::rollBack();
        $this->assertDatabaseCount('library_item_access', 0);
        $this->customer($user);
        foreach (['library_item_access', 'library_contents'] as $table) {
            $this->postJson('/api/sync/upload', ['operations' => [['op' => 'PUT', 'type' => $table, 'id' => (string) Str::uuid(), 'data' => ['user_id' => $user->id, 'item_id' => $item->id]]]])->assertForbidden();
        }
    }
}
