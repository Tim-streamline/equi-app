<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\CommunityPost;
use App\Models\User;
use App\Support\WebLogin;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebSessionTest extends TestCase
{
    use RefreshDatabase;

    private string $keyPath;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->keyPath = tempnam(sys_get_temp_dir(), 'web-auth-');
        file_put_contents($this->keyPath, $private);
        config(['powersync.private_key_path' => $this->keyPath]);
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    protected function tearDown(): void
    {
        unlink($this->keyPath);
        parent::tearDown();
    }

    private function csrf(): string
    {
        $token = $this->getJson('/web-session/csrf')->assertOk()->json('token');
        $this->withHeader('X-CSRF-TOKEN', $token);

        return $token;
    }

    public function test_login_and_renewal_use_a_session_and_logout_revokes_renewal(): void
    {
        $user = User::factory()->create(['password' => 'web-password']);
        $old = $this->csrf();
        $result = $this->postJson('/web-session/login', ['email' => $user->email, 'password' => 'web-password'])
            ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonMissingPath('password');
        $claims = JWT::decode($result->json('token'), new Key($this->publicKey, 'RS256'));
        $this->assertSame($user->id, $claims->sub);
        $this->assertNotSame($old, session()->token());
        $this->postJson('/web-session/token')->assertStatus(419);
        $this->csrf();
        $this->postJson('/web-session/token')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->postJson('/web-session/logout')->assertOk();
        $this->csrf();
        $this->postJson('/web-session/token')->assertUnauthorized();
    }

    public function test_session_mutations_require_csrf_and_disabled_users_cannot_login_or_renew(): void
    {
        $user = User::factory()->create(['password' => 'web-password']);
        foreach (['login', 'token', 'logout'] as $action) {
            $this->postJson('/web-session/'.$action)->assertStatus(419);
        }
        $this->csrf();
        $this->postJson('/web-session/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        $this->postJson('/web-session/token')->assertUnauthorized();
        $this->postJson('/web-session/login', ['email' => $user->email, 'password' => 'web-password'])->assertOk();
        $user->update(['disabled_at' => now()]);
        $this->app['auth']->forgetGuards();
        $this->csrf();
        $this->postJson('/web-session/token')->assertUnauthorized();
        $this->postJson('/web-session/login', ['email' => $user->email, 'password' => 'web-password'])->assertUnauthorized();
    }

    public function test_browser_media_requires_session_and_native_login_still_works_without_csrf(): void
    {
        $this->get('/web-session/media/00000000-0000-4000-8000-000000000001')->assertUnauthorized();
        $user = User::factory()->create(['password' => 'native-password']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'native-password'])
            ->assertOk()->assertJsonPath('user.id', $user->id);
        $this->csrf();
        $this->postJson('/web-session/token')->assertUnauthorized();
    }

    public function test_browser_media_keeps_existing_visibility_rules(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['password' => 'web-password']);
        $post = CommunityPost::create(['author_user_id' => $user->id, 'author_name' => 'Test', 'body' => 'Photo']);
        $id = (string) Str::uuid();
        Storage::disk('local')->put('community/test.jpg', 'image-content');
        DB::table('community_media')->insert([
            'id' => $id, 'post_id' => $post->id, 'path' => 'community/test.jpg',
            'mime_type' => 'image/jpeg', 'size' => 13, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->csrf();
        $this->postJson('/web-session/login', ['email' => $user->email, 'password' => 'web-password'])->assertOk();
        $this->get('/web-session/media/'.$id)->assertOk();
        $post->update(['moderation_status' => 'hidden']);
        $this->getJson('/web-session/media/'.$id)->assertNotFound();
        $user->update(['disabled_at' => now()]);
        Auth::forgetGuards();
        $this->getJson('/web-session/media/'.$id)->assertUnauthorized();
    }

    public function test_persistent_login_survives_a_lost_server_session_without_extending_the_deadline(): void
    {
        $this->freezeTime();
        $deadline = now()->addDays(30);
        $user = User::factory()->create(['password' => 'web-password']);
        $this->csrf();
        $response = $this->postJson('/web-session/login', ['email' => $user->email, 'password' => 'web-password'])->assertOk();
        $rememberName = Auth::guard('web')->getRecallerName();
        $deadlineName = app(WebLogin::class)->cookieName();
        $cookies = [];
        foreach ([$rememberName, $deadlineName] as $name) {
            $cookie = $response->getCookie($name);
            $this->assertNotNull($cookie);
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertSame($deadline->timestamp, $cookie->getExpiresTime());
            $cookies[$name] = $cookie->getValue();
        }

        // A browser restart after the database session has expired retains only
        // persistent cookies, with no authenticated server session to fall back on.
        session()->flush();
        Auth::forgetGuards();
        $this->withCredentials()->withCookies($cookies);
        $this->travelTo($deadline->copy()->subDay());
        $this->csrf();
        $this->postJson('/web-session/token')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertTrue(Auth::guard('web')->viaRemember());
        $this->assertSame($deadline->timestamp, session(WebLogin::SESSION_KEY.'.expires_at'));

        // A refreshed access token must not outlive the original login deadline.
        $this->travelTo($deadline->copy()->subSeconds(30));
        Auth::forgetGuards();
        $this->csrf();
        $renewal = $this->postJson('/web-session/token')->assertOk()->assertJsonPath('expires_in', 30);
        $claims = json_decode(base64_decode(strtr(explode('.', $renewal->json('token'))[1], '-_', '+/')), true);
        $this->assertSame($deadline->timestamp, $claims['exp']);

        // Even if expired cookies are replayed, server-side checks enforce expiry.
        $this->travelTo($deadline);
        Auth::forgetGuards();
        $this->postJson('/web-session/token')->assertUnauthorized();
        $this->getJson('/web-session/media/00000000-0000-4000-8000-000000000001')->assertUnauthorized();
    }

    public function test_signing_in_again_starts_a_new_thirty_day_period(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['password' => 'web-password']);
        $credentials = ['email' => $user->email, 'password' => 'web-password'];
        $this->csrf();
        $this->postJson('/web-session/login', $credentials)->assertOk();
        $this->travel(29)->days();
        Auth::forgetGuards();
        $this->csrf();
        $this->postJson('/web-session/login', $credentials)->assertOk();
        $this->assertSame(now()->addDays(30)->timestamp, session(WebLogin::SESSION_KEY.'.expires_at'));
        $this->travel(2)->days();
        Auth::forgetGuards();
        $this->csrf();
        $this->postJson('/web-session/token')->assertOk();
    }

    public function test_existing_legacy_session_upgrades_without_credentials_and_keeps_a_fixed_deadline(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['remember_token' => null]);
        $this->csrf();
        // Reproduce the previous login implementation: server session only.
        Auth::guard('web')->login($user);
        Auth::forgetGuards();
        $this->csrf();
        $deadline = now()->addDays(30);
        $response = $this->postJson('/web-session/token')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertNotEmpty($user->fresh()->getRememberToken());
        $cookies = [];
        foreach ([Auth::guard('web')->getRecallerName(), app(WebLogin::class)->cookieName()] as $name) {
            $cookie = $response->getCookie($name);
            $this->assertNotNull($cookie);
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertSame($deadline->timestamp, $cookie->getExpiresTime());
            $cookies[$name] = $cookie->getValue();
        }
        session()->flush();
        Auth::forgetGuards();
        $this->withCredentials()->withCookies($cookies);
        $this->travel(29)->days();
        $this->csrf();
        $this->postJson('/web-session/token')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertTrue(Auth::guard('web')->viaRemember());
        $this->assertSame($deadline->timestamp, session(WebLogin::SESSION_KEY.'.expires_at'));
        $this->travelTo($deadline);
        Auth::forgetGuards();
        $this->csrf();
        $this->postJson('/web-session/token')->assertUnauthorized();
    }

    public function test_disabled_legacy_session_is_not_upgraded(): void
    {
        $user = User::factory()->create(['disabled_at' => now(), 'remember_token' => null]);
        $this->csrf();
        Auth::guard('web')->login($user);
        Auth::forgetGuards();
        $this->csrf();
        $this->postJson('/web-session/token')->assertUnauthorized();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'remember_token' => null]);
        $this->assertGuest('web');
    }

    public function test_expired_legacy_session_cannot_be_upgraded(): void
    {
        $user = User::factory()->create(['remember_token' => null]);
        $this->csrf();
        Auth::guard('web')->login($user);
        // Simulate the old server session having expired before the next visit.
        session()->flush();
        Auth::forgetGuards();
        $this->csrf();
        $this->postJson('/web-session/token')->assertUnauthorized();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'remember_token' => null]);
    }

    public function test_remember_cookie_without_a_deadline_cannot_be_upgraded_as_a_legacy_session(): void
    {
        $user = User::factory()->create(['password' => 'web-password']);
        $this->csrf();
        $response = $this->postJson('/web-session/login', ['email' => $user->email, 'password' => 'web-password'])->assertOk();
        $rememberName = Auth::guard('web')->getRecallerName();
        $this->withCredentials()->withCookie($rememberName, $response->getCookie($rememberName)->getValue());
        session()->flush();
        Auth::forgetGuards();
        $this->csrf();
        $this->postJson('/web-session/token')->assertUnauthorized();
        Auth::forgetGuards();
        $this->csrf();
        $this->postJson('/web-session/token')->assertUnauthorized();
    }

    public function test_existing_session_with_invalid_login_metadata_is_not_upgraded(): void
    {
        $user = User::factory()->create();
        $this->csrf();
        Auth::guard('web')->login($user);
        session()->put(WebLogin::SESSION_KEY, []);
        Auth::forgetGuards();
        $this->csrf();
        $this->postJson('/web-session/token')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_logout_revokes_persistent_cookies_even_if_replayed(): void
    {
        $user = User::factory()->create(['password' => 'web-password']);
        $this->csrf();
        $response = $this->postJson('/web-session/login', ['email' => $user->email, 'password' => 'web-password'])->assertOk();
        $cookies = [];
        foreach ([Auth::guard('web')->getRecallerName(), app(WebLogin::class)->cookieName()] as $name) {
            $cookies[$name] = $response->getCookie($name)->getValue();
        }
        $this->withCredentials()->withCookies($cookies);
        Auth::forgetGuards();
        $this->csrf();
        $logout = $this->postJson('/web-session/logout')->assertOk();
        foreach (array_keys($cookies) as $name) {
            $logout->assertCookieExpired($name);
        }
        session()->flush();
        Auth::forgetGuards();
        $this->withCookies($cookies);
        $this->csrf();
        $this->postJson('/web-session/token')->assertUnauthorized();
    }

    public function test_password_reset_revokes_an_existing_web_login(): void
    {
        $user = User::factory()->create(['password' => 'web-password']);
        $this->csrf();
        $this->postJson('/web-session/login', ['email' => $user->email, 'password' => 'web-password'])->assertOk();
        $this->csrf();
        $this->post('/web-session/password/reset', [
            'email' => $user->email,
            'token' => Password::broker('users')->createToken($user),
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertRedirect();
        Auth::forgetGuards();
        $this->postJson('/web-session/token')->assertUnauthorized();
    }

    public static function adminResetLoginStates(): array
    {
        return [
            'active browser session' => [false],
            'saved cookies after server session expires' => [true],
        ];
    }

    #[DataProvider('adminResetLoginStates')]
    public function test_admin_password_reset_revokes_persistent_login_and_accepts_only_the_new_password(bool $restoreFromCookies): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $this->csrf();
        $login = $this->postJson('/web-session/login', ['email' => $user->email, 'password' => 'old-password'])->assertOk();
        $cookies = [];
        foreach ([Auth::guard('web')->getRecallerName(), app(WebLogin::class)->cookieName()] as $name) {
            $cookies[$name] = $login->getCookie($name)->getValue();
        }

        $admin = AdminUser::create([
            'name' => 'Admin', 'email' => 'reset-admin@example.test', 'password' => 'admin-password',
            'role' => 'owner', 'active' => true,
        ]);
        Auth::guard('admin')->login($admin);
        $this->csrf();
        $this->post('/admin/users/'.$user->id.'/reset-password')->assertRedirect()->assertSessionHas('success');
        $password = Str::after(session('success'), 'Password reset. Temporary password: ');
        $this->assertDatabaseHas('audit_logs', ['target_id' => $user->id, 'action' => 'reset_password', 'admin_user_id' => $admin->id]);

        if ($restoreFromCookies) {
            session()->flush();
            $this->travel(29)->days();
        }
        Auth::forgetGuards();
        $this->withCredentials()->withCookies($cookies);
        $this->csrf();
        $this->postJson('/web-session/token')->assertUnauthorized();
        $this->getJson('/web-session/media/00000000-0000-4000-8000-000000000001')->assertUnauthorized();
        if (! $restoreFromCookies) {
            $this->assertAuthenticatedAs($admin, 'admin');
        }

        $this->csrf();
        $this->postJson('/web-session/login', ['email' => $user->email, 'password' => 'old-password'])->assertUnauthorized();
        $this->postJson('/web-session/login', ['email' => $user->email, 'password' => $password])->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_expiring_customer_login_preserves_the_independent_admin_guard(): void
    {
        $user = User::factory()->create(['password' => 'web-password']);
        $this->csrf();
        $this->postJson('/web-session/login', ['email' => $user->email, 'password' => 'web-password'])->assertOk();
        $this->travel(30)->days();
        $admin = AdminUser::create([
            'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'admin-password',
            'role' => 'owner', 'active' => true,
        ]);
        Auth::guard('admin')->login($admin);
        Auth::forgetGuards();
        $this->csrf();
        $this->postJson('/web-session/token')->assertUnauthorized();
        $this->assertAuthenticatedAs($admin, 'admin');
    }
}
