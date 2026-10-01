<?php

namespace App\Notifications;

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

        return (new MailMessage)->subject('Stel je EquiApp-wachtwoord opnieuw in')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('Je hebt een link aangevraagd om je wachtwoord opnieuw in te stellen.')
            ->action('Nieuw wachtwoord instellen', $url)
            ->line('Deze link is '.config('auth.passwords.users.expire').' minuten geldig en kan één keer worden gebruikt.')
            ->line('Heb je dit niet aangevraagd? Dan hoef je niets te doen.');
    }
}
