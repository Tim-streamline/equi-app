<?php

namespace Tests\Feature;

use App\Http\Controllers\PowerSyncAuthController;
use App\Models\Horse;
use App\Models\Protocol;
use App\Models\User;
use App\Support\SubscriptionAccess;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasSubscription;
use Tests\TestCase;

class SubscriptionAccessTest extends TestCase
{
    use HasSubscription, RefreshDatabase;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $private);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory.'/subscription-private.pem', $private);
        file_put_contents($directory.'/subscription-public.pem', $this->publicKey);
        config(['powersync.private_key_path' => $directory.'/subscription-private.pem', 'powersync.public_key_path' => $directory.'/subscription-public.pem', 'powersync.token_ttl' => 3600]);
    }

    private function token(User $user): string
    {
        return app(PowerSyncAuthController::class)->tokenResponse($user)->getData(true)['token'];
    }

    public function test_direct_protocol_creation_creates_one_intake_and_reuses_it_on_retry(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Rolo']);
        $other = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $response = $this->withToken($this->token($user))->postJson('/api/protocol-purchases/create', ['horse_id' => $horse->id])->assertOk();
        $pair = $response->json();
        $this->assertDatabaseHas('protocols', ['id' => $pair['protocolId'], 'horse_id' => $horse->id, 'status' => 'draft']);
        $this->assertDatabaseHas('intake_bookings', ['id' => $pair['bookingId'], 'protocol_id' => $pair['protocolId'], 'horse_id' => $horse->id, 'user_id' => $user->id, 'intake_status' => 'draft']);
        $this->postJson('/api/protocol-purchases/create', ['horse_id' => $horse->id])->assertOk()->assertExactJson($pair);
        $this->assertDatabaseCount('protocols', 1);
        $this->assertDatabaseCount('intake_bookings', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->postJson('/api/protocol-purchases/create', ['horse_id' => $other->id])->assertOk();
        $this->assertDatabaseCount('protocols', 2);
        $this->assertDatabaseCount('intake_bookings', 2);
        Protocol::findOrFail($pair['protocolId'])->update(['status' => 'completed']);
        $next = $this->postJson('/api/protocol-purchases/create', ['horse_id' => $horse->id])->assertOk();
        $this->assertNotSame($pair['bookingId'], $next->json('bookingId'));
        $this->assertDatabaseCount('protocols', 3);
        $this->assertDatabaseCount('intake_bookings', 3);
    }

    public function test_direct_protocol_creation_requires_subscription_and_horse_ownership(): void
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Rolo']);
        $this->postJson('/api/protocol-purchases/create', ['horse_id' => $horse->id])->assertUnauthorized();
        $this->withToken($this->token($user))->postJson('/api/protocol-purchases/create', ['horse_id' => $horse->id])->assertStatus(402);
        $this->subscribe($user);
        $foreign = Horse::create(['owner_id' => User::factory()->create()->id, 'name' => 'Other']);
        $this->postJson('/api/protocol-purchases/create', ['horse_id' => $foreign->id])->assertNotFound();
        $horse->update(['status' => 'archived']);
        $this->postJson('/api/protocol-purchases/create', ['horse_id' => $horse->id])->assertNotFound();
        $this->postJson('/api/protocol-purchases/create', ['horse_id' => 'invalid'])->assertUnprocessable();
        $this->assertDatabaseCount('protocols', 0);
        $this->assertDatabaseCount('intake_bookings', 0);
    }

    public function test_unsubscribed_account_can_reach_renewal_but_no_app_features(): void
    {
        $user = User::factory()->create();
        $this->withToken($this->token($user))->getJson('/api/subscription')->assertOk()->assertJsonPath('subscribed', false);
        $this->getJson('/api/home')->assertStatus(402);
        $this->getJson('/api/community')->assertStatus(402);
        $this->getJson('/api/library/access')->assertStatus(402);
        $this->postJson('/api/sync/upload', ['operations' => []])->assertStatus(402);
        $this->postJson('/api/subscription/renew')->assertOk()->assertJsonPath('subscribed', true);
        $this->getJson('/api/subscription')->assertOk()->assertJsonPath('subscribed', true);
        $this->getJson('/api/community')->assertOk();
    }

    public function test_activation_reuses_expired_subscription_and_ignores_another_user_in_the_request(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $subscription = $this->subscribe($user);
        $subscription->update(['status' => 'cancelled', 'paid_through' => now()->subDay(),
            'cancelled_at' => today()->subDay(), 'cancel_requested_at' => now()->subDays(2), 'ended_at' => now()->subDay()]);

        $this->withToken($this->token($user))->postJson('/api/subscription/renew', ['user_id' => $other->id])
            ->assertOk()->assertJsonPath('subscribed', true);
        $this->assertSame($subscription->id, app(SubscriptionAccess::class)->active($user->id)->id);
        $this->assertNull(app(SubscriptionAccess::class)->active($other->id));
        $this->assertNull($subscription->fresh()->cancel_requested_at);
        $this->postJson('/api/subscription/renew')->assertOk();
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('protocols', 0);
        $this->assertDatabaseCount('intake_bookings', 0);
    }

    public function test_activation_requires_authentication_and_keeps_an_active_subscription_unchanged(): void
    {
        $this->postJson('/api/subscription/renew')->assertUnauthorized();
        $user = User::factory()->create();
        $subscription = $this->subscribe($user);
        $before = $subscription->fresh()->getAttributes();
        $this->withToken($this->token($user))->postJson('/api/subscription/renew')->assertOk();
        $this->assertSame($before, $subscription->fresh()->getAttributes());
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_expiration_blocks_old_tokens_and_new_sync_tokens_do_not_grant_feature_streams(): void
    {
        $user = User::factory()->create();
        $s = $this->subscribe($user);
        $s->update(['paid_through' => now()->addMinutes(2)]);
        $token = $this->token($user);
        $claims = JWT::decode($token, new Key($this->publicKey, 'RS256'));
        $this->assertSame(1, $claims->subscribed);
        $this->assertLessThanOrEqual(now()->addMinutes(2)->timestamp, $claims->exp);
        $s->update(['paid_through' => now()->subSecond()]);
        $this->withToken($token)->getJson('/api/home')->assertStatus(402);
        $claims = JWT::decode($this->token($user), new Key($this->publicKey, 'RS256'));
        $this->assertSame(0, $claims->subscribed);
        $this->assertNull(app(SubscriptionAccess::class)->active($user->id));
    }

    public function test_legacy_paid_plans_have_the_same_access_but_free_is_not_a_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user);
        foreach (['basic', 'plus', 'subscription', 'free'] as $slug) {
            $subscription->plan->update(['slug' => $slug]);
            $token = $this->token($user);
            $this->withToken($token)->getJson('/api/subscription')->assertOk()->assertJsonPath('subscribed', $slug !== 'free');
            $this->getJson('/api/community')->assertStatus($slug === 'free' ? 402 : 200);
        }
    }
}
