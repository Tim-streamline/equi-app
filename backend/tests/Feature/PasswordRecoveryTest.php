<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\CustomerPasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_email_uses_an_expiring_single_use_token_and_returns_to_login(): void
    {
        Notification::fake();
        config(['app.web_url' => 'https://customer.example.test']);
        $user = User::factory()->create(['email' => 'MixedCase@example.test']);
        $this->postJson('/api/auth/forgot-password', ['email' => ' MIXEDCASE@example.test '])->assertOk();
        $notification = Notification::sent($user, CustomerPasswordReset::class)->first();
        $this->assertNotNull($notification);
        $mail = $notification->toMail($user);
        $this->assertStringContainsString('/web-session/password/reset/'.$notification->token, $mail->actionUrl);
        $this->get($mail->actionUrl)->assertOk()->assertSee('Nieuw wachtwoord instellen')->assertSee('MixedCase@example.test');
        $this->assertDatabaseMissing('password_reset_tokens', ['token' => $notification->token]);
        $payload = ['email' => $user->email, 'token' => $notification->token, 'password' => 'a-new-test-password', 'password_confirmation' => 'a-new-test-password'];
        $this->post('/web-session/password/reset', $payload)->assertRedirect('https://customer.example.test/onboarding/welcome?passwordReset=1');
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->assertFalse(Hash::check('password', $user->fresh()->password));
        $this->post('/web-session/password/reset', $payload)->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    public function test_reset_email_is_rendered_and_handed_to_the_mail_transport(): void
    {
        config(['mail.default' => 'array']);
        $user = User::factory()->create();
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $mail = $messages->first()->getOriginalMessage();
        $this->assertSame($user->email, $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('/web-session/password/reset/', $mail->getHtmlBody());
        $this->assertStringContainsString('60 minuten geldig', $mail->getHtmlBody());
    }

    public function test_unknown_disabled_and_throttled_accounts_receive_the_same_response(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $disabled = User::factory()->create(['disabled_at' => now()]);
        $message = $this->postJson('/web-session/forgot-password', ['email' => $user->email])->assertOk()->json();
        foreach ([$user->email, $disabled->email, 'unknown@example.test'] as $email) {
            $this->postJson('/web-session/forgot-password', ['email' => $email])->assertOk()->assertExactJson($message);
        }
        Notification::assertSentToTimes($user, CustomerPasswordReset::class, 1);
        Notification::assertNotSentTo($disabled, CustomerPasswordReset::class);
    }

    public function test_expired_wrong_and_disabled_account_tokens_cannot_change_the_password(): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password'];
        $this->post('/web-session/password/reset', [...$payload, 'token' => 'wrong-token'])->assertSessionHasErrors('email');
        $this->postJson('/web-session/password/reset', [...$payload, 'password_confirmation' => 'different'])->assertUnprocessable();
        $user->update(['disabled_at' => now()]);
        $this->post('/web-session/password/reset', $payload)->assertSessionHasErrors('email');
        $user->update(['disabled_at' => null]);
        $this->travel(61)->minutes();
        $this->post('/web-session/password/reset', $payload)->assertSessionHasErrors('email');
        $this->assertFalse(Hash::check($payload['password'], $user->fresh()->password));
    }
}
