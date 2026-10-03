<?php

namespace App\Mail;

use App\Support\Brand;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RegistrationConfirmation extends Mailable
{
    public function __construct(public string $name, public string $confirmationUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bevestig je e-mailadres | '.Brand::NAME);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.registration-confirmation-text');
    }
}
