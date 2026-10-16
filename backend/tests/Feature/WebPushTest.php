<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebPushTest extends TestCase
{
    use RefreshDatabase;

    private function asUser(User $user): void
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

    public function test_browser_registration_is_authenticated_encrypted_and_transfers_between_accounts(): void
    {
        $data = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/test', 'keys' => ['p256dh' => str_repeat('a', 87), 'auth' => str_repeat('b', 22)]];
        $this->postJson('/api/notifications/web-push', $data)->assertUnauthorized();
        $one = User::factory()->create();
        $two = User::factory()->create();
        $this->asUser($one);
        $this->postJson('/api/notifications/web-push', $data)->assertOk();
        $record = WebPushSubscription::first();
        $this->assertSame($data, $record->subscription);
        $this->assertStringNotContainsString('fcm.googleapis.com', $record->getRawOriginal('subscription'));
        $this->asUser($two);
        $this->postJson('/api/notifications/web-push', $data)->assertOk();
        $this->assertSame($two->id, $record->fresh()->user_id);
        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $this->asUser($one);
        $this->postJson('/api/notifications/web-push/remove', $data)->assertOk();
        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $this->asUser($two);
        $this->postJson('/api/notifications/web-push/remove', $data)->assertOk();
        $this->assertDatabaseCount('web_push_subscriptions', 0);
    }

    public function test_arbitrary_private_endpoints_credentials_and_ports_are_rejected(): void
    {
        $this->asUser(User::factory()->create());
        foreach (['http://127.0.0.1/test', 'https://127.0.0.1/test', 'https://fcm.googleapis.com.attacker.test/push', 'https://fcm.googleapis.com:8443/push', 'https://user@fcm.googleapis.com/push'] as $endpoint) {
            $this->postJson('/api/notifications/web-push', ['endpoint' => $endpoint, 'keys' => ['p256dh' => str_repeat('a', 87), 'auth' => str_repeat('b', 22)]])->assertUnprocessable();
        }
        $this->assertDatabaseCount('web_push_subscriptions', 0);
    }
}
