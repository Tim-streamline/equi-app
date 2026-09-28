<?php

namespace Tests\Feature;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private string $privatePath;

    private string $publicPath;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $private);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->privatePath = tempnam(sys_get_temp_dir(), 'registration-private-');
        $this->publicPath = tempnam(sys_get_temp_dir(), 'registration-public-');
        file_put_contents($this->privatePath, $private);
        file_put_contents($this->publicPath, $this->publicKey);
        config(['powersync.private_key_path' => $this->privatePath, 'powersync.public_key_path' => $this->publicPath]);
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
        unlink($this->privatePath);
        unlink($this->publicPath);
        parent::tearDown();
    }

    private function account(array $override = []): array
    {
        return array_merge(['name' => ' New Owner ', 'email' => ' New.Owner@Example.Test ', 'password' => 'password-for-test', 'password_confirmation' => 'password-for-test'], $override);
    }

    public function test_native_registration_signs_in_a_normal_user_without_creating_a_horse(): void
    {
        $response = $this->postJson('/api/auth/register', $this->account(['admin_note' => 'forged', 'disabled_at' => now()]))
            ->assertCreated()->assertJsonPath('user.email', 'new.owner@example.test')->assertJsonPath('user.name', 'New Owner')->assertJsonMissingPath('password');
        $user = User::findOrFail($response->json('user.id'));
        $this->assertTrue(Hash::check('password-for-test', $user->password));
        $this->assertNull($user->admin_note);
        $this->assertNull($user->disabled_at);
        $this->assertDatabaseCount('horses', 0);
        $this->assertDatabaseCount('admin_users', 0);
        $claims = JWT::decode($response->json('token'), new Key($this->publicKey, 'RS256'));
        $this->assertSame($user->id, $claims->sub);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password-for-test'])->assertOk();
    }

    public function test_new_owner_can_add_a_horse_through_the_existing_sync_path(): void
    {
        $registered = $this->postJson('/api/auth/register', $this->account())->assertCreated();
        $horseId = (string) Str::uuid();
        $this->withHeader('Authorization', 'Bearer '.$registered->json('token'))->postJson('/api/sync/upload', ['operations' => [[
            'op' => 'PUT', 'type' => 'horses', 'id' => $horseId,
            'data' => ['owner_id' => $registered->json('user.id'), 'name' => 'Nova', 'breed' => null, 'age' => null, 'sex' => null, 'weight_kg' => null, 'status' => 'active'],
        ]]])->assertOk()->assertJsonPath('applied', 1);
        $this->assertDatabaseHas('horses', ['id' => $horseId, 'owner_id' => $registered->json('user.id'), 'name' => 'Nova']);
    }

    public function test_registration_rejects_invalid_fields_and_password_confirmation(): void
    {
        $this->postJson('/api/auth/register', $this->account(['name' => ' ', 'email' => 'bad', 'password' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
        $this->postJson('/api/auth/register', $this->account(['password_confirmation' => 'different']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/auth/register', $this->account(['name' => [], 'email' => []]))->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_email_cannot_be_registered_again_regardless_of_case(): void
    {
        $user = User::factory()->create(['email' => 'New.Owner@Example.Test']);
        $this->postJson('/api/auth/register', $this->account())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($user->name, $user->fresh()->name);
    }

    public function test_web_registration_requires_csrf_rotates_session_and_can_renew(): void
    {
        $this->postJson('/web-session/register', $this->account())->assertStatus(419);
        $csrf = $this->getJson('/web-session/csrf')->json('token');
        $registered = $this->withHeader('X-CSRF-TOKEN', $csrf)->postJson('/web-session/register', $this->account())->assertCreated();
        $this->assertAuthenticatedAs(User::findOrFail($registered->json('user.id')), 'web');
        $this->assertNotSame($csrf, session()->token());
        $this->postJson('/web-session/token')->assertStatus(419);
        $csrf = $this->getJson('/web-session/csrf')->json('token');
        $this->withHeader('X-CSRF-TOKEN', $csrf)->postJson('/web-session/token')->assertOk()->assertJsonPath('user.id', $registered->json('user.id'));
        $this->assertDatabaseCount('horses', 0);
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/register', [])->assertUnprocessable();
        }
        $this->postJson('/api/auth/register', [])->assertStatus(429);
    }
}
