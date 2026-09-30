<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RegistrationConfirmation extends Mailable
{
    public function __construct(public string $name, public string $confirmationUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bevestig je e-mailadres | Equi App');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.registration-confirmation', text: 'mail.registration-confirmation-text');
    }
}
