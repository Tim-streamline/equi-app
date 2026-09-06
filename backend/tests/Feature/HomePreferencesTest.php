<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\User;
use App\Models\UserHomePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HomePreferencesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $ownerId = $this->owner->id;
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($ownerId)
        {
            public function __construct(private string $userId) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->userId);

                return $next($request);
            }
        });
    }

    public function test_default_is_enabled_and_sync_preserves_dismissed_tip_ids_without_double_encoding(): void
    {
        $this->upload($this->owner->id, 'PUT', [])->assertOk()->assertJsonPath('applied', 1);
        $this->assertTrue(UserHomePreference::findOrFail($this->owner->id)->seasonal_tips_enabled);
        $ids = [(string) Str::uuid(), (string) Str::uuid()];
        $this->upload($this->owner->id, 'PATCH', ['seasonal_tips_enabled' => 0, 'dismissed_tip_ids' => json_encode($ids)])->assertOk();
        $preference = UserHomePreference::findOrFail($this->owner->id);
        $this->assertFalse($preference->seasonal_tips_enabled);
        $this->assertSame($ids, $preference->dismissed_tip_ids);
        $this->upload($this->owner->id, 'PATCH', ['seasonal_tips_enabled' => 1, 'dismissed_tip_ids' => '[]'])->assertOk();
        $this->assertTrue($preference->fresh()->seasonal_tips_enabled);
        $this->assertSame([], $preference->fresh()->dismissed_tip_ids);
    }

    public function test_an_account_cannot_create_change_or_delete_another_accounts_preferences(): void
    {
        $other = User::factory()->create();
        $this->upload($other->id, 'PUT', ['seasonal_tips_enabled' => 0])->assertForbidden();
        $this->assertDatabaseMissing('user_home_preferences', ['id' => $other->id]);
        $preference = new UserHomePreference;
        $preference->id = $other->id;
        $preference->save();
        $this->upload($other->id, 'PATCH', ['seasonal_tips_enabled' => 0])->assertForbidden();
        $this->upload($other->id, 'DELETE', [])->assertForbidden();
        $this->assertTrue($preference->fresh()->seasonal_tips_enabled);
    }

    public function test_invalid_sync_preferences_are_rejected_without_persisting_partial_changes(): void
    {
        foreach ([
            ['seasonal_tips_enabled' => 'off'],
            ['dismissed_tip_ids' => 'invalid-json'],
            ['dismissed_tip_ids' => 'null'],
            ['dismissed_tip_ids' => '["not-a-uuid"]'],
            ['unexpected' => true],
        ] as $data) {
            $this->upload($this->owner->id, 'PUT', $data)->assertUnprocessable();
            $this->assertDatabaseMissing('user_home_preferences', ['id' => $this->owner->id]);
        }
    }

    private function upload(string $id, string $op, array $data)
    {
        return $this->postJson('/api/sync/upload', ['operations' => [
            ['type' => 'user_home_preferences', 'id' => $id, 'op' => $op, 'data' => $data],
        ]]);
    }
}
