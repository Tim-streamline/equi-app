<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_authenticated_scoped_validated_and_transfers_a_shared_device(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $other->notificationPreferences()->create(['push_token' => 'ExpoPushToken[device]']);
        $this->postJson('/api/notifications/push-token', ['token' => 'ExpoPushToken[device]', 'timezone' => 'Europe/Amsterdam'])->assertUnauthorized();
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($user->id)
        {
            public function __construct(private string $id) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->id);

                return $next($request);
            }
        });
        $this->postJson('/api/notifications/push-token', ['token' => 'invalid', 'timezone' => 'Mars'])->assertUnprocessable();
        $this->postJson('/api/notifications/push-token', ['token' => 'ExpoPushToken[device]', 'timezone' => 'Europe/Amsterdam', 'user_id' => $other->id])->assertOk();
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->id, 'push_token' => 'ExpoPushToken[device]', 'timezone' => 'Europe/Amsterdam']);
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $other->id, 'push_token' => null]);
        $this->postJson('/api/notifications/push-token', ['token' => null, 'timezone' => 'Europe/Amsterdam'])->assertOk();
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->id, 'push_token' => null]);
        $user->update(['disabled_at' => now()]);
        $this->postJson('/api/notifications/push-token', ['token' => 'ExpoPushToken[device]', 'timezone' => 'Europe/Amsterdam'])->assertNotFound();
    }
}
