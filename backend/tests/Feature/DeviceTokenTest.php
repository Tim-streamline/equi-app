<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\MasterKeyLogin;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER = 'test-only-support-key-0123456789abcdef';

    private string $privatePath;

    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $this->privatePath = tempnam(sys_get_temp_dir(), 'device-private-');
        $this->publicPath = tempnam(sys_get_temp_dir(), 'device-public-');
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

    private function deviceToken(User $user, string $password = 'customer-password'): string
    {
        return $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password, 'device_name' => 'android'])
            ->assertOk()->json('device_token');
    }

    private function exchange(string $token)
    {
        return $this->postJson('/api/auth/token', ['device_token' => $token]);
    }

    private function claims(string $jwt): object
    {
        // The tests travel in time; validate iat/exp against the test clock.
        JWT::$timestamp = now()->timestamp;
        try {
            return JWT::decode($jwt, new Key(file_get_contents($this->publicPath), 'RS256'));
        } finally {
            JWT::$timestamp = null;
        }
    }

    public function test_device_token_replaces_the_password_for_fresh_sync_tokens_and_expires_after_ninety_unused_days(): void
    {
        $user = User::factory()->create(['password' => 'customer-password']);
        $token = $this->deviceToken($user);
        $stored = PersonalAccessToken::sole();
        $this->assertNotSame($token, $stored->token);
        $this->assertTrue($stored->expires_at->equalTo(now()->addDays(90)));

        $this->travel(89)->days();
        $response = $this->exchange($token)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($user->id, $this->claims($response->json('token'))->sub);
        $this->assertSame(3600, $response->json('expires_in'));
        $this->assertTrue($stored->fresh()->expires_at->equalTo(now()->addDays(90)));
        $this->assertTrue($stored->fresh()->last_used_at->equalTo(now()));

        $this->travel(90 * 24 * 60 * 60 + 1)->seconds();
        $this->exchange($token)->assertUnauthorized();
    }

    public function test_failed_password_logins_on_the_same_network_do_not_block_token_exchange(): void
    {
        $user = User::factory()->create(['password' => 'customer-password']);
        $token = $this->deviceToken($user);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => 'someone.else@example.test', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/auth/login', ['email' => 'someone.else@example.test', 'password' => 'wrong'])->assertTooManyRequests();
        $this->exchange($token)->assertOk();
    }

    public function test_protocol_capability_is_per_token_and_absence_keeps_legacy_clients(): void
    {
        $user = User::factory()->create(['password' => 'customer-password']);
        $old = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'customer-password', 'device_name' => 'old'])->assertOk();
        $this->assertFalse(property_exists($this->claims($old->json('token')), 'protocol_doses'));
        $new = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'customer-password',
            'device_name' => 'new', 'capabilities' => ['protocol-doses', 'unknown']])->assertOk();
        $this->assertSame(1, $this->claims($new->json('token'))->protocol_doses);
        $refreshed = $this->postJson('/api/auth/token', ['device_token' => $old->json('device_token'), 'capabilities' => ['protocol-doses']])->assertOk();
        $this->assertSame(1, $this->claims($refreshed->json('token'))->protocol_doses);
        $this->assertFalse(property_exists($this->claims($this->exchange($new->json('device_token'))->json('token')), 'protocol_doses'));
    }

    public function test_login_without_a_device_name_keeps_working_for_older_app_versions(): void
    {
        $user = User::factory()->create(['password' => 'customer-password']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'customer-password'])
            ->assertOk()->assertJsonMissingPath('device_token');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unknown_malformed_and_revoked_tokens_are_rejected(): void
    {
        $user = User::factory()->create(['password' => 'customer-password']);
        foreach (['', 'not-a-token', 'abc|'.str_repeat('a', 48), '1|'.str_repeat('a', 48), str_repeat('9', 30).'|x'] as $token) {
            $this->exchange($token)->assertStatus($token === '' ? 422 : 401);
        }
        $token = $this->deviceToken($user);
        $this->postJson('/api/auth/logout', ['device_token' => $token])->assertNoContent();
        $this->exchange($token)->assertUnauthorized();
        $this->postJson('/api/auth/logout', ['device_token' => 'unknown'])->assertNoContent();
    }

    public function test_logout_signs_out_only_that_device(): void
    {
        $user = User::factory()->create(['password' => 'customer-password']);
        $phone = $this->deviceToken($user);
        $tablet = $this->deviceToken($user);
        $this->postJson('/api/auth/logout', ['device_token' => $phone])->assertNoContent();
        $this->exchange($phone)->assertUnauthorized();
        $this->exchange($tablet)->assertOk();
    }

    public function test_password_reset_and_admin_actions_sign_out_every_device(): void
    {
        $user = User::factory()->create(['password' => 'customer-password']);
        $token = $this->deviceToken($user);
        $this->post('/web-session/password/reset', ['email' => $user->email, 'token' => Password::broker('users')->createToken($user),
            'password' => 'a-new-test-password', 'password_confirmation' => 'a-new-test-password'])->assertRedirect();
        $this->exchange($token)->assertUnauthorized();

        $this->actingAs(AdminUser::create(['name' => 'Owner', 'email' => 'devices@example.test', 'password' => 'password', 'role' => 'owner', 'active' => true]), 'admin');
        $token = $this->deviceToken($user, 'a-new-test-password');
        $this->post("/admin/users/{$user->id}/reset-password")->assertRedirect();
        $this->exchange($token)->assertUnauthorized();

        $user->update(['password' => 'customer-password']);
        $token = $this->deviceToken($user);
        $this->post("/admin/users/{$user->id}/disable")->assertRedirect();
        $this->exchange($token)->assertUnauthorized();
    }

    public function test_disabled_account_cannot_exchange_a_remaining_token(): void
    {
        $user = User::factory()->create(['password' => 'customer-password']);
        $token = $this->deviceToken($user);
        $user->update(['disabled_at' => now()]);
        $this->exchange($token)->assertForbidden();
    }

    public function test_support_device_token_ends_after_an_hour_and_with_the_master_key(): void
    {
        $user = User::factory()->create();
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::MASTER, 'device_name' => 'android'])
            ->assertOk()->json('device_token');
        $this->assertTrue(PersonalAccessToken::sole()->expires_at->equalTo(now()->addHour()));

        $this->travel(58)->minutes();
        $response = $this->exchange($token)->assertOk();
        $this->assertSame(120, $response->json('expires_in'));
        $this->assertSame(app(MasterKeyLogin::class)->fingerprint(), $this->claims($response->json('token'))->support_key);
        // The deadline never moves, and refreshing is not a new master-key login.
        $this->assertTrue(PersonalAccessToken::sole()->expires_at->equalTo(now()->addMinutes(2)));
        $this->assertSame(1, AuditLog::where('action', 'support_login')->count());

        config(['support.master_key_hash' => hash('sha256', 'a-rotated-support-key-0123456789abcdef')]);
        $this->exchange($token)->assertUnauthorized();
    }
}
