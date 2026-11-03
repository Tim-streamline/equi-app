<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\Horse;
use App\Models\LibraryItem;
use App\Models\LibraryProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaleLibraryProgressSyncTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\HasSubscription;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->subscribe($this->user);
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($this->user->id)
        {
            public function __construct(private string $id) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->id);

                return $next($request);
            }
        });
    }

    private function viewOperation(array $data = []): array
    {
        return ['op' => 'PUT', 'type' => 'library_progress', 'id' => (string) Str::uuid(), 'data' => $data + [
            'user_id' => $this->user->id, 'item_id' => (string) Str::uuid(), 'last_viewed_at' => now()->toISOString(),
        ]];
    }

    public function test_missing_library_item_is_acknowledged_and_later_changes_are_applied(): void
    {
        $horse = Horse::create(['owner_id' => $this->user->id, 'name' => 'Before', 'status' => 'active']);
        $view = $this->viewOperation();
        $this->postJson('/api/sync/upload', ['operations' => [$view, [
            'op' => 'PATCH', 'type' => 'horses', 'id' => $horse->id, 'data' => ['name' => 'After'],
        ]]])->assertOk()->assertJson(['applied' => 1, 'skipped' => 1]);
        $this->assertSame('After', $horse->fresh()->name);
        $this->assertDatabaseMissing('library_progress', ['id' => $view['id']]);
        // A lost acknowledgement must also be safe to retry.
        $this->postJson('/api/sync/upload', ['operations' => [$view]])->assertOk()->assertJson(['applied' => 0, 'skipped' => 1]);
    }

    public function test_view_queued_before_item_deletion_is_acknowledged(): void
    {
        $item = LibraryItem::create(['slug' => (string) Str::uuid(), 'title' => 'Deleted lesson', 'format' => 'article']);
        $view = $this->viewOperation(['item_id' => $item->id]);
        $item->delete();
        $this->postJson('/api/sync/upload', ['operations' => [$view]])->assertOk()->assertJson(['applied' => 0, 'skipped' => 1]);
        $this->assertDatabaseMissing('library_progress', ['id' => $view['id']]);
    }

    public function test_malformed_view_is_not_acknowledged_even_when_item_is_missing(): void
    {
        foreach ([['item_id' => 'invalid'], ['last_viewed_at' => 'invalid']] as $data) {
            $this->postJson('/api/sync/upload', ['operations' => [$this->viewOperation($data)]])->assertUnprocessable();
        }
    }

    public function test_offline_revisit_patch_with_preserved_identity_recovers_after_item_deletion(): void
    {
        $item = LibraryItem::create(['slug' => (string) Str::uuid(), 'title' => 'Deleted lesson', 'format' => 'article']);
        $progress = LibraryProgress::create(['user_id' => $this->user->id, 'item_id' => $item->id, 'last_viewed_at' => now()->subDay()]);
        $patch = ['op' => 'PATCH', 'type' => 'library_progress', 'id' => $progress->id, 'data' => [
            'last_viewed_at' => now()->toISOString(), 'user_id' => $this->user->id, 'item_id' => $item->id,
        ]];
        $item->delete();
        $this->assertDatabaseMissing('library_progress', ['id' => $progress->id]);
        $horse = Horse::create(['owner_id' => $this->user->id, 'name' => 'Before', 'status' => 'active']);
        $this->postJson('/api/sync/upload', ['operations' => [$patch, [
            'op' => 'PATCH', 'type' => 'horses', 'id' => $horse->id, 'data' => ['name' => 'After'],
        ]]])->assertOk()->assertJson(['applied' => 1, 'skipped' => 1]);
        $this->assertSame('After', $horse->fresh()->name);
        $this->postJson('/api/sync/upload', ['operations' => [$patch]])->assertOk()->assertJson(['applied' => 0, 'skipped' => 1]);
        $this->assertDatabaseMissing('library_progress', ['id' => $progress->id]);

        $patch['data']['user_id'] = User::factory()->create()->id;
        $this->postJson('/api/sync/upload', ['operations' => [$patch]])->assertForbidden();
    }

    public function test_missing_row_patch_without_identity_is_not_silently_discarded(): void
    {
        $patch = ['op' => 'PATCH', 'type' => 'library_progress', 'id' => (string) Str::uuid(), 'data' => [
            'last_viewed_at' => now()->toISOString(),
        ]];
        $this->postJson('/api/sync/upload', ['operations' => [$patch]])->assertForbidden();
        $patch['data']['user_id'] = $this->user->id;
        $this->postJson('/api/sync/upload', ['operations' => [$patch]])->assertUnprocessable();
    }

    public function test_missing_item_does_not_bypass_owner_authorization(): void
    {
        $this->postJson('/api/sync/upload', ['operations' => [$this->viewOperation(['user_id' => User::factory()->create()->id])]])->assertForbidden();
    }

    public function test_existing_other_owners_row_cannot_be_acknowledged_as_stale(): void
    {
        $other = User::factory()->create();
        $item = LibraryItem::create(['slug' => (string) Str::uuid(), 'title' => 'Lesson', 'format' => 'article']);
        $existing = LibraryProgress::create(['user_id' => $other->id, 'item_id' => $item->id]);
        $view = $this->viewOperation();
        $view['id'] = $existing->id;
        $this->postJson('/api/sync/upload', ['operations' => [$view]])->assertForbidden();
        $this->assertSame($other->id, $existing->fresh()->user_id);
    }

    public function test_other_invalid_changes_still_reject_the_transaction(): void
    {
        $this->postJson('/api/sync/upload', ['operations' => [$this->viewOperation(), [
            'op' => 'PATCH', 'type' => 'horses', 'id' => (string) Str::uuid(), 'data' => ['name' => 'Forbidden'],
        ]]])->assertForbidden();
    }
}
