<?php

namespace App\Listeners;

use App\Mail\RegistrationConfirmation;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;

class RecordRegistrationMailDelivery
{
    public function handle(MessageSent $event): void
    {
        if (($event->data['__laravel_mailable'] ?? null) !== RegistrationConfirmation::class) {
            return;
        }

        Log::info('registration_mail_handed_off', [
            'message_id' => $event->sent->getMessageId(),
            'recipient_domains' => array_values(array_unique(array_map(
                fn ($address) => substr(strrchr($address->getAddress(), '@'), 1),
                $event->message->getTo(),
            ))),
        ]);
    }
}
