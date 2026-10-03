<?php

namespace Tests\Feature;

use App\Mail\RegistrationConfirmation;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RegistrationMailDeliveryTest extends TestCase
{
    public function test_registration_handoff_logs_the_transport_id_without_the_address_or_confirmation_link(): void
    {
        config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);
        $mail = new MailManager($this->app);
        Log::shouldReceive('info')->once()->withArgs(fn ($message, $context) => $message === 'registration_mail_handed_off'
            && array_keys($context) === ['message_id', 'recipient_domains']
            && is_string($context['message_id']) && $context['message_id'] !== ''
            && $context['recipient_domains'] === ['example.test']
        );

        $sent = $mail->to('private.person@example.test')->send(
            new RegistrationConfirmation('Private person', 'https://equi-app.online/registration/confirm/private-uid'),
        );

        $this->assertNotNull($sent);
    }

    public function test_unrelated_mail_is_not_logged_as_registration_delivery(): void
    {
        config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);
        Log::shouldReceive('info')->never();
        $mail = new MailManager($this->app);
        $mail->raw('Test', fn ($message) => $message->to('private.person@example.test')->subject('Unrelated mail'));
    }
}
