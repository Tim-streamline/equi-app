<?php

namespace App\Mail;

use App\Support\Brand;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Confirmation to the customer that their protocol intake was received. */
class IntakeReceived extends Mailable
{
    public function __construct(public string $name, public string $horseName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Je intake voor '.$this->horseName.' is ontvangen | '.Brand::NAME);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.intake-received-text', with: ['brandName' => Brand::NAME]);
    }
}
