<?php

namespace Tests\Feature;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Signed-in API budgets belong to one account and one endpoint. Accounts behind a shared
 * carrier or stable IP must not use up each other's budget, and one endpoint's retries must
 * not block another endpoint.
 */
class JwtRateLimitTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\HasSubscription;

    private string $privateKey = '';

    private string $publicPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        // A key file of its own, so test processes running side by side do not replace it.
        $this->publicPath = storage_path('framework/testing/powersync_public_'.Str::random(12).'.pem');
        if (! is_dir(dirname($this->publicPath))) {
            mkdir(dirname($this->publicPath), 0777, true);
        }
        file_put_contents($this->publicPath, openssl_pkey_get_details($key)['key']);
        config([
            'powersync.public_key_path' => $this->publicPath,
            'powersync.audience' => 'equinova',
            'powersync.issuer' => 'equinova-laravel',
            'powersync.key_id' => 'test-key',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->publicPath);
        parent::tearDown();
    }

    public function test_jwt_routes_use_named_per_account_limiters(): void
    {
        $routes = app('router')->getRoutes();
        foreach ([
            'api/protocol-purchases/create' => 'throttle:protocol-purchase-create',
            'api/intakes/'.Str::uuid().'/attachments' => 'throttle:intake-attachment',
            'api/notifications/web-push' => 'throttle:web-push-subscribe',
            'api/library/'.Str::uuid().'/unlock' => 'throttle:library-unlock',
            'api/library/credits/purchase' => 'throttle:credit-purchase',
            'api/library/credits/temporary-top-up' => 'throttle:credit-temporary-top-up',
            'api/horses/'.Str::uuid().'/protocol-orders' => 'throttle:protocol-orders',
            'api/protocols/'.Str::uuid().'/evaluation' => 'throttle:protocol-evaluation',
        ] as $uri => $throttle) {
            $route = $routes->match(\Illuminate\Http\Request::create('/'.$uri, 'POST'));
            $this->assertContains($throttle, $route->gatherMiddleware(), $uri);
            $this->assertEmpty(array_filter($route->gatherMiddleware(), fn ($m) => preg_match('/^throttle:\d/', $m)), $uri);
        }
    }

    public function test_accounts_behind_one_ip_have_separate_budgets(): void
    {
        [$alice, $bob] = [$this->subscriber(), $this->subscriber()];
        for ($i = 0; $i < 30; $i++) {
            $this->assertNotSame(429, $this->postAs($alice, '/api/notifications/web-push')->status());
        }
        $this->postAs($alice, '/api/notifications/web-push')->assertStatus(429);
        // Same network, other account: unaffected.
        $this->assertNotSame(429, $this->postAs($bob, '/api/notifications/web-push')->status());
    }

    public function test_each_endpoint_has_its_own_budget_for_one_account(): void
    {
        $alice = $this->subscriber();
        for ($i = 0; $i < 15; $i++) {
            $this->assertNotSame(429, $this->postAs($alice, '/api/library/credits/purchase')->status());
        }
        $this->postAs($alice, '/api/library/credits/purchase')->assertStatus(429);
        // Neither the other credit endpoint nor an unrelated one is affected.
        $this->assertNotSame(429, $this->postAs($alice, '/api/library/credits/temporary-top-up')->status());
        $this->assertNotSame(429, $this->postAs($alice, '/api/notifications/web-push')->status());
    }

    public function test_one_endpoint_budget_spans_its_resources(): void
    {
        $alice = $this->subscriber();
        [$first, $second] = [(string) Str::uuid(), (string) Str::uuid()];
        for ($i = 0; $i < 30; $i++) {
            $this->assertNotSame(429, $this->postAs($alice, "/api/intakes/{$first}/attachments")->status());
            $this->assertNotSame(429, $this->postAs($alice, "/api/intakes/{$second}/attachments")->status());
        }
        $this->postAs($alice, '/api/intakes/'.Str::uuid().'/attachments')->assertStatus(429);
    }

    public function test_order_ticks_and_evaluations_have_their_own_per_account_budgets(): void
    {
        [$alice, $bob] = [$this->subscriber(), $this->subscriber()];
        $evaluation = '/api/protocols/'.Str::uuid().'/evaluation';
        for ($i = 0; $i < 30; $i++) {
            $this->assertNotSame(429, $this->postAs($alice, $evaluation)->status());
        }
        $this->postAs($alice, $evaluation)->assertStatus(429);
        // Order ticks are a separate bucket, and another account behind the same IP is unaffected.
        $this->assertNotSame(429, $this->postAs($alice, '/api/horses/'.Str::uuid().'/protocol-orders')->status());
        $this->assertNotSame(429, $this->postAs($bob, $evaluation)->status());
        for ($i = 0; $i < 59; $i++) {
            $this->assertNotSame(429, $this->postAs($alice, '/api/horses/'.Str::uuid().'/protocol-orders')->status());
        }
        $this->postAs($alice, '/api/horses/'.Str::uuid().'/protocol-orders')->assertStatus(429);
    }

    private function subscriber(): User
    {
        $user = User::factory()->create();
        $this->subscribe($user);

        return $user;
    }

    private function postAs(User $user, string $uri): TestResponse
    {
        $now = time();
        $token = JWT::encode([
            'iss' => 'equinova-laravel', 'aud' => 'equinova', 'sub' => (string) $user->id, 'iat' => $now, 'exp' => $now + 3600,
        ], $this->privateKey, 'RS256', 'test-key');

        return $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('Authorization', 'Bearer '.$token)->postJson($uri, []);
    }
}
