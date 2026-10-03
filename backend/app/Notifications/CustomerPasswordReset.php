<?php

namespace App\Notifications;

use App\Support\Brand;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerPasswordReset extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.url'), '/').'/web-session/password/reset/'.rawurlencode($this->token).'?'.http_build_query(['email' => $notifiable->getEmailForPasswordReset()]);

        return (new MailMessage)->subject('Stel je '.Brand::NAME.'-wachtwoord opnieuw in')
            ->text('mail.password-reset-text', [
                'name' => $notifiable->name,
                'resetUrl' => $url,
                'expiresInMinutes' => config('auth.passwords.users.expire'),
                'brandName' => Brand::NAME,
            ]);
    }
}
