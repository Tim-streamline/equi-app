<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\MasterKeyLogin;
use App\Support\WebLogin;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class MasterKeyLoginTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER = 'test-only-support-key-0123456789abcdef';

    private string $privatePath;

    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $this->privatePath = tempnam(sys_get_temp_dir(), 'support-private-');
        $this->publicPath = tempnam(sys_get_temp_dir(), 'support-public-');
        file_put_contents($this->privatePath, $private);
        file_put_contents($this->publicPath, openssl_pkey_get_details($key)['key']);
        config([
            'powersync.private_key_path' => $this->privatePath,
            'powersync.public_key_path' => $this->publicPath,
            'support.master_key_enabled' => true,
            'support.master_key_hash' => hash('sha256', self::MASTER),
        ]);
    }

    protected function tearDown(): void
    {
        unlink($this->privatePath);
        unlink($this->publicPath);
        parent::tearDown();
    }

    private function credentials(User $user): array
    {
        return ['email' => strtoupper($user->email), 'password' => self::MASTER];
    }

    public function test_master_key_authenticates_multiple_customers_with_scoped_tokens_and_audit_entries(): void
    {
        foreach (User::factory()->count(2)->create() as $user) {
            $before = $user->fresh()->getAttributes();
            $response = $this->postJson('/api/auth/login', $this->credentials($user))
                ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('expires_in', 300);
            $claims = JWT::decode($response->json('token'), new Key(file_get_contents($this->publicPath), 'RS256'));
            $this->assertSame($user->id, $claims->sub);
            $this->assertSame(0, $claims->subscribed);
            $this->assertSame(app(MasterKeyLogin::class)->fingerprint(), $claims->support_key);
            $this->assertSame($before, $user->fresh()->getAttributes());
            $audit = AuditLog::where('target_id', $user->id)->sole();
            $this->assertSame('support_login', $audit->action);
            $this->assertSame('api', $audit->after['channel']);
            $this->assertSame('127.0.0.1', $audit->ip_address);
            $this->assertStringNotContainsString(self::MASTER, $audit->toJson());
            $this->assertStringNotContainsString(config('support.master_key_hash'), $audit->toJson());
        }
    }

    public function test_browser_support_session_is_short_lived_and_does_not_change_customer_credentials(): void
    {
        $user = User::factory()->create(['remember_token' => null]);
        $before = $user->fresh()->getAttributes();
        $response = $this->postJson('/web-session/login', $this->credentials($user))->assertOk();
        $deadline = now()->addHour()->timestamp;
        $this->assertSame($deadline, session(WebLogin::SESSION_KEY.'.expires_at'));
        $this->assertSame($before, $user->fresh()->getAttributes());
        $remember = $response->getCookie(Auth::guard('web')->getRecallerName());
        $this->assertTrue($remember === null || $remember->getExpiresTime() < now()->timestamp);
        $this->assertSame('web', AuditLog::sole()->after['channel']);
        $this->travel(59)->minutes();
        Auth::forgetGuards();
        $this->postJson('/web-session/token')->assertOk()->assertJsonPath('expires_in', 60);
        $this->assertSame($deadline, session(WebLogin::SESSION_KEY.'.expires_at'));
        $this->travel(1)->minutes();
        Auth::forgetGuards();
        $this->postJson('/web-session/token')->assertUnauthorized();
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_support_logout_does_not_rotate_customer_remember_token(): void
    {
        $user = User::factory()->create(['remember_token' => 'customer-remember-token']);
        $this->postJson('/web-session/login', $this->credentials($user))->assertOk();
        $this->postJson('/web-session/logout')->assertOk();
        $this->postJson('/web-session/token')->assertUnauthorized();
        $this->assertSame('customer-remember-token', $user->fresh()->getRememberToken());
    }

    public function test_support_login_clears_previous_persistent_login_cookies(): void
    {
        $owner = User::factory()->create(['password' => 'normal-password']);
        $target = User::factory()->create();
        $login = $this->postJson('/web-session/login', ['email' => $owner->email, 'password' => 'normal-password'])->assertOk();
        $rememberName = Auth::guard('web')->getRecallerName();
        $this->withCredentials()->withCookie($rememberName, $login->getCookie($rememberName)->getValue());
        Auth::forgetGuards();
        $beforeToken = $owner->fresh()->getRememberToken();
        $response = $this->postJson('/web-session/login', $this->credentials($target))->assertOk();
        foreach ([Auth::guard('web')->getRecallerName(), app(WebLogin::class)->cookieName()] as $name) {
            $this->assertLessThan(now()->timestamp, $response->getCookie($name)->getExpiresTime());
        }
        $this->assertSame($beforeToken, $owner->fresh()->getRememberToken());
        session()->flush();
        Auth::forgetGuards();
        $this->postJson('/web-session/token')->assertUnauthorized();
    }

    public function test_key_rotation_revokes_browser_renewal_and_existing_api_access(): void
    {
        $user = User::factory()->create();
        $token = $this->postJson('/web-session/login', $this->credentials($user))->assertOk()->json('token');
        // Subscription restrictions remain effective for support logins.
        $this->withToken($token)->getJson('/api/home')->assertStatus(402);
        config(['support.master_key_hash' => hash('sha256', 'a-different-random-support-key-0123456789')]);
        $this->withToken($token)->getJson('/api/home')->assertUnauthorized();
        Auth::forgetGuards();
        $this->postJson('/web-session/token')->assertUnauthorized();
    }

    public function test_disabling_support_revokes_browser_renewal_and_existing_api_access(): void
    {
        $user = User::factory()->create();
        $token = $this->postJson('/web-session/login', $this->credentials($user))->assertOk()->json('token');
        config(['support.master_key_enabled' => false]);
        $this->withToken($token)->getJson('/api/home')->assertUnauthorized();
        Auth::forgetGuards();
        $this->postJson('/web-session/token')->assertUnauthorized();
    }

    public function test_disabled_accounts_and_unknown_emails_cannot_use_master_key(): void
    {
        $user = User::factory()->create(['disabled_at' => now()]);
        $this->postJson('/api/auth/login', $this->credentials($user))->assertForbidden();
        $this->postJson('/web-session/login', $this->credentials($user))->assertUnauthorized();
        foreach (['/api/auth/login', '/web-session/login'] as $url) {
            $this->postJson($url, ['email' => 'missing@example.com', 'password' => self::MASTER])->assertUnauthorized();
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_missing_disabled_malformed_or_short_keys_fail_closed(): void
    {
        $user = User::factory()->create();
        foreach (['', 'malformed', hash('sha256', 'short')] as $hash) {
            config(['support.master_key_hash' => $hash]);
            foreach (['/api/auth/login', '/web-session/login'] as $url) {
                $this->postJson($url, ['email' => $user->email, 'password' => $hash === hash('sha256', 'short') ? 'short' : self::MASTER])->assertUnauthorized();
            }
        }
        config(['support.master_key_hash' => hash('sha256', self::MASTER), 'support.master_key_enabled' => false]);
        $this->postJson('/api/auth/login', $this->credentials($user))->assertUnauthorized();
        $this->postJson('/web-session/login', $this->credentials($user))->assertUnauthorized();
    }

    public function test_production_rejects_master_key_but_normal_passwords_still_work(): void
    {
        $this->app['env'] = 'production';
        $this->withHeader('X-CSRF-TOKEN', $this->getJson('/web-session/csrf')->assertOk()->json('token'));
        $user = User::factory()->create(['password' => 'normal-password']);
        foreach (['/api/auth/login', '/web-session/login'] as $url) {
            $this->postJson($url, $this->credentials($user))->assertUnauthorized();
            $response = $this->postJson($url, ['email' => $user->email, 'password' => 'normal-password'])->assertOk();
            $claims = JWT::decode($response->json('token'), new Key(file_get_contents($this->publicPath), 'RS256'));
            $this->assertObjectNotHasProperty('support_key', $claims);
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_web_master_login_requires_csrf(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $user = User::factory()->create();
        $this->postJson('/web-session/login', $this->credentials($user))->assertStatus(419);
        $csrf = $this->getJson('/web-session/csrf')->assertOk()->json('token');
        $this->withHeader('X-CSRF-TOKEN', $csrf)->postJson('/web-session/login', $this->credentials($user))->assertOk();
        $this->assertNotSame($csrf, session()->token());
    }

    public function test_login_rate_limit_is_per_account_across_web_and_native_with_a_wider_cap_per_ip(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson($attempt % 2 ? '/api/auth/login' : '/web-session/login', [
                'email' => $attempt % 3 ? $user->email : strtoupper($user->email), 'password' => 'wrong',
            ])->assertUnauthorized();
        }
        $this->postJson('/api/auth/login', $this->credentials($user))->assertTooManyRequests();
        $this->postJson('/web-session/login', $this->credentials($user))->assertTooManyRequests();
        // Failed logins for one account do not lock out another account on the same network.
        $this->postJson('/api/auth/login', $this->credentials($other))->assertOk();

        // Trying many accounts from one IP still stops.
        for ($attempt = 10; $attempt < 100; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => "attempt{$attempt}@example.com", 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/auth/login', $this->credentials($other))->assertTooManyRequests();
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/login', $this->credentials($user))->assertOk();
    }

    public function test_successful_native_logins_do_not_count_towards_the_login_rate_limit(): void
    {
        $user = User::factory()->create(['password' => 'customer-password']);
        // The native app re-authenticates for every PowerSync token, often from a shared IP.
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'customer-password'])->assertOk();
        }
        $this->postJson('/api/auth/login', $this->credentials($user))->assertOk();
    }
}
