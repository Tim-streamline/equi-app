<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class IntakeSubmitted extends Mailable
{
    public function __construct(public string $horseName, public string $userName, public string $submittedAt, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Nieuwe protocolintake ingediend – '.$this->horseName);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.intake-submitted', text: 'mail.intake-submitted-text');
    }
}
