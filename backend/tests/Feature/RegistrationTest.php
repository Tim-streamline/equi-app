<?php

namespace Tests\Feature;

use App\Mail\RegistrationConfirmation;
use App\Models\User;
use App\Support\CreditLedger;
use App\Support\WebLogin;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
        Cache::flush();
        Mail::fake();
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

    private function beginRegistration(array $overrides = [], string $path = '/api/auth/register'): array
    {
        $response = $this->postJson($path, $this->account($overrides))->assertStatus(202)
            ->assertJsonMissingPath('token')->assertJsonMissingPath('user')->assertJsonMissingPath('code');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('credit_grants', 0);
        $this->assertGuest('web');
        $mail = Mail::sent(RegistrationConfirmation::class)->last();
        $this->assertTrue($mail->hasTo('new.owner@example.test'));

        return ['registration_token' => $response->json('registration_token')];
    }

    private function completeRegistration(array $overrides = [])
    {
        $proof = $this->beginRegistration($overrides);
        $this->get(Mail::sent(RegistrationConfirmation::class)->last()->confirmationUrl)->assertOk();

        return $this->postJson('/api/auth/register/complete', $proof);
    }

    public function test_native_registration_signs_in_a_normal_user_without_creating_a_horse(): void
    {
        $response = $this->completeRegistration(['admin_note' => 'forged', 'disabled_at' => now()])
            ->assertCreated()->assertJsonPath('user.email', 'new.owner@example.test')->assertJsonPath('user.name', 'New Owner')->assertJsonMissingPath('password');
        $user = User::findOrFail($response->json('user.id'));
        $this->assertTrue(Hash::check('password-for-test', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('pending_registrations', ['user_id' => $user->id, 'password_hash' => '']);
        $this->assertNull($user->admin_note);
        $this->assertNull($user->disabled_at);
        $this->assertDatabaseCount('horses', 0);
        $this->assertDatabaseCount('admin_users', 0);
        $claims = JWT::decode($response->json('token'), new Key($this->publicKey, 'RS256'));
        $this->assertSame($user->id, $claims->sub);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password-for-test'])->assertOk();
    }

    public function test_staging_registration_receives_seven_test_credits_and_login_does_not_repeat_them(): void
    {
        $this->app->instance('env', 'staging');
        $response = $this->completeRegistration()->assertCreated();
        $user = User::findOrFail($response->json('user.id'));
        $this->assertSame(7, app(CreditLedger::class)->summary($user)['balance']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password-for-test'])->assertOk();
        $this->assertSame(7, app(CreditLedger::class)->summary($user)['balance']);
        $this->assertDatabaseCount('credit_grants', 1);
    }

    public function test_new_owner_can_add_a_horse_through_the_existing_sync_path(): void
    {
        $registered = $this->completeRegistration()->assertCreated();
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
        $this->freezeTime();
        $this->postJson('/web-session/register', $this->account())->assertStatus(419);
        $csrf = $this->getJson('/web-session/csrf')->json('token');
        $this->withHeader('X-CSRF-TOKEN', $csrf);
        $verification = $this->beginRegistration([], '/web-session/register');
        $this->postJson('/web-session/register/status', $verification)->assertOk()->assertJsonPath('status', 'pending');
        $this->get(Mail::sent(RegistrationConfirmation::class)->last()->confirmationUrl)->assertOk()->assertSee('Je e-mailadres is bevestigd');
        $this->assertGuest('web');
        $this->postJson('/web-session/register/status', $verification)->assertOk()->assertJsonPath('status', 'confirmed');
        $this->withHeader('X-CSRF-TOKEN', 'invalid')->postJson('/web-session/register/complete', $verification)->assertStatus(419);
        $registered = $this->withHeader('X-CSRF-TOKEN', $csrf)->postJson('/web-session/register/complete', $verification)->assertCreated();
        foreach ([Auth::guard('web')->getRecallerName(), app(WebLogin::class)->cookieName()] as $name) {
            $cookie = $registered->getCookie($name);
            $this->assertNotNull($cookie);
            $this->assertSame(now()->addDays(30)->timestamp, $cookie->getExpiresTime());
        }
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

    public function test_pending_registration_cannot_log_in_and_stores_only_hashes(): void
    {
        $proof = $this->beginRegistration();
        $pending = DB::table('pending_registrations')->first();
        $this->assertTrue(Hash::check('password-for-test', $pending->password_hash));
        $uid = basename(Mail::sent(RegistrationConfirmation::class)->last()->confirmationUrl);
        $this->assertTrue(Str::isUuid($uid));
        $this->assertSame(hash('sha256', $uid), $pending->uid_hash);
        $this->assertNotSame($proof['registration_token'], $pending->token_hash);
        $this->postJson('/api/auth/login', ['email' => 'new.owner@example.test', 'password' => 'password-for-test'])->assertUnauthorized();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_polling_cannot_confirm_an_address_and_email_uid_cannot_log_in(): void
    {
        $proof = $this->beginRegistration();
        $this->postJson('/api/auth/register/status', $proof)->assertOk()->assertExactJson(['status' => 'pending']);
        $this->postJson('/api/auth/register/complete', $proof)->assertUnprocessable();
        $url = Mail::sent(RegistrationConfirmation::class)->last()->confirmationUrl;
        $this->head($url)->assertOk();
        $this->postJson('/api/auth/register/status', $proof)->assertOk()->assertJsonPath('status', 'pending');
        $this->postJson('/api/auth/register/complete', ['registration_token' => basename($url)])->assertUnprocessable();
        $this->get('/registration/confirm/'.$proof['registration_token'])->assertNotFound();
        $this->get('/registration/confirm/'.Str::uuid())->assertStatus(410);
        $this->assertDatabaseCount('users', 0);
        $this->postJson('/api/auth/register/status', $proof)->assertOk()->assertExactJson(['status' => 'pending']);
    }

    public function test_expired_and_unknown_challenges_cannot_create_accounts(): void
    {
        $proof = $this->beginRegistration();
        $this->postJson('/api/auth/register/complete', ['registration_token' => str_repeat('x', 64)])->assertUnprocessable();
        $this->travel(15)->minutes();
        $this->get(Mail::sent(RegistrationConfirmation::class)->last()->confirmationUrl)->assertStatus(410)->assertSee('Deze link is niet meer geldig');
        $this->postJson('/api/auth/register/status', $proof)->assertOk()->assertJsonPath('status', 'expired');
        $this->postJson('/api/auth/register/complete', $proof)->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_resending_replaces_the_link_and_retries_do_not_create_duplicate_accounts(): void
    {
        $old = $this->beginRegistration();
        $oldUrl = Mail::sent(RegistrationConfirmation::class)->last()->confirmationUrl;
        $this->postJson('/api/auth/register', $this->account())->assertStatus(429);
        Mail::assertSentCount(1);
        $this->travel(61)->seconds();
        $new = $this->beginRegistration();
        $this->get($oldUrl)->assertStatus(410);
        $this->postJson('/api/auth/register/status', $old)->assertOk()->assertJsonPath('status', 'expired');
        $this->postJson('/api/auth/register/complete', $old)->assertUnprocessable();
        $url = Mail::sent(RegistrationConfirmation::class)->last()->confirmationUrl;
        $this->get($url)->assertOk();
        $this->get($url)->assertOk();
        $this->assertGuest('web');
        $response = $this->postJson('/api/auth/register/complete', $new)->assertCreated();
        $this->postJson('/api/auth/register/complete', $new)->assertCreated()->assertJsonPath('user.id', $response->json('user.id'));
        $this->assertDatabaseCount('users', 1);
        $this->travel(2)->minutes();
        $this->postJson('/api/auth/register/complete', $new)->assertUnprocessable();
    }

    public function test_email_claimed_before_confirmation_cannot_be_overwritten(): void
    {
        $proof = $this->beginRegistration();
        $this->get(Mail::sent(RegistrationConfirmation::class)->last()->confirmationUrl)->assertOk();
        $existing = User::factory()->create(['email' => 'New.Owner@Example.Test']);
        $this->postJson('/api/auth/register/complete', $proof)->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($existing->password, $existing->fresh()->password);
    }

    public function test_mail_failure_does_not_create_an_account_or_pending_registration(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->postJson('/api/auth/register', $this->account())->assertStatus(503)
            ->assertJsonPath('message', 'De bevestigingsmail kon niet worden verstuurd. Je account is nog niet aangemaakt. Probeer het later opnieuw.');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('pending_registrations', 0);
    }

    public function test_confirmation_email_is_rendered_in_dutch(): void
    {
        $proof = $this->beginRegistration();
        $mail = Mail::sent(RegistrationConfirmation::class)->last();
        $this->assertSame('Bevestig je e-mailadres | EquiApp', $mail->envelope()->subject);
        $mail->assertSeeInText('Hallo New Owner,');
        $mail->assertSeeInText('Klik op de onderstaande link om je e-mailadres te bevestigen:');
        $mail->assertSeeInText('De link is 15 minuten geldig.');
        $mail->assertSeeInText($mail->confirmationUrl);
        $mail->assertDontSeeInText($proof['registration_token']);
    }

    public function test_real_mail_transport_delivers_a_usable_dutch_confirmation(): void
    {
        config(['mail.default' => 'array', 'mail.from.address' => 'sender@example.test']);
        Mail::swap(new MailManager($this->app));
        $response = $this->postJson('/api/auth/register', $this->account())->assertStatus(202);
        $this->assertDatabaseCount('users', 0);
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages->first()->getOriginalMessage();
        $this->assertSame('new.owner@example.test', $message->getTo()[0]->getAddress());
        $this->assertSame('Bevestig je e-mailadres | EquiApp', $message->getSubject());
        $this->assertNull($message->getHtmlBody());
        $this->assertSame('text', $message->getBody()->getMediaType());
        $this->assertSame('plain', $message->getBody()->getMediaSubtype());
        $this->assertStringContainsString('Hallo New Owner,', $message->getTextBody());
        $this->assertStringContainsString('De link is 15 minuten geldig.', $message->getTextBody());
        $this->assertStringNotContainsString($response->json('registration_token'), $message->getTextBody());
        preg_match('~https?://[^\s]+/registration/confirm/[a-f0-9-]+~', $message->getTextBody(), $matches);
        $this->get($matches[0])->assertOk();
        $this->assertGuest('web');
        $this->postJson('/api/auth/register/complete', [
            'registration_token' => $response->json('registration_token'),
        ])->assertCreated();
    }

    public function test_email_link_uses_configured_public_url_instead_of_request_host(): void
    {
        config(['app.url' => 'https://api.equi.example']);
        $this->withHeader('Host', 'untrusted.example')->postJson('/api/auth/register', $this->account())->assertStatus(202);
        $this->assertStringStartsWith('https://api.equi.example/registration/confirm/', Mail::sent(RegistrationConfirmation::class)->last()->confirmationUrl);
    }

    public function test_waiting_polls_do_not_exhaust_the_login_rate_limit(): void
    {
        $proof = $this->beginRegistration();
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/auth/register/status', $proof)->assertOk()->assertJsonPath('status', 'pending');
        }
        $this->get(Mail::sent(RegistrationConfirmation::class)->last()->confirmationUrl)->assertOk();
        $this->postJson('/api/auth/register/complete', $proof)->assertCreated();
    }
}
