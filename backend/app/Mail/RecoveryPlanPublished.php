<?php

namespace App\Mail;

use App\Support\Brand;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells the customer that the recovery plan (protocol) for their horse is ready in the app. */
class RecoveryPlanPublished extends Mailable
{
    public function __construct(public string $name, public string $horseName, public string $appUrl, public bool $hasSubscription) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Het herstelplan voor '.$this->horseName.' staat klaar | '.Brand::NAME);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.recovery-plan-published-text');
    }
}
