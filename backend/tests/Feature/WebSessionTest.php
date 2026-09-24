<?php

namespace Tests\Feature;

use App\Models\CommunityPost;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $user = User::factory()->create();
        $post = CommunityPost::create(['author_user_id' => $user->id, 'author_name' => 'Test', 'body' => 'Photo']);
        $id = (string) Str::uuid();
        Storage::disk('local')->put('community/test.jpg', 'image-content');
        DB::table('community_media')->insert([
            'id' => $id, 'post_id' => $post->id, 'path' => 'community/test.jpg',
            'mime_type' => 'image/jpeg', 'size' => 13, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($user, 'web')->get('/web-session/media/'.$id)->assertOk();
        $post->update(['moderation_status' => 'hidden']);
        $this->getJson('/web-session/media/'.$id)->assertNotFound();
        $user->update(['disabled_at' => now()]);
        $this->getJson('/web-session/media/'.$id)->assertUnauthorized();
    }
}
