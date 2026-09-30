<?php

namespace App\Support;

use App\Mail\IntakeSubmitted;
use App\Models\IntakeBooking;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class IntakeSubmissionMail
{
    public function send(string $bookingId): void
    {
        // A durable atomic claim survives overlapping scheduler runs and process crashes.
        // Never automatically reclaim a sending/failed row: SMTP may already have accepted it.
        $claimed = IntakeBooking::whereKey($bookingId)->where('submission_email_status', 'pending')
            ->where('intake_status', 'submitted')->whereNotNull('submitted_at')
            ->update(['submission_email_status' => 'sending', 'submission_email_claimed_at' => now()]);
        if (! $claimed) {
            return;
        }

        try {
            $booking = IntakeBooking::with('answers', 'user', 'horse')->findOrFail($bookingId);
            $sent = Mail::to('Contact@depaardentherapeut.nl')->send(new IntakeSubmitted(
                $booking->intakeHorseName(), $booking->user?->name ?? 'Onbekend',
                $booking->submitted_at_label, route('admin.bookings.show', $booking),
            ));
            if (! $sent) {
                throw new RuntimeException('De mailtransport heeft de verzending niet bevestigd.');
            }
            $booking->forceFill(['submission_email_status' => 'sent', 'submission_email_sent_at' => now(), 'submission_email_error' => null])->save();
        } catch (Throwable $error) {
            IntakeBooking::whereKey($bookingId)->update([
                'submission_email_status' => 'failed', 'submission_email_error' => Str::limit($error->getMessage(), 1000),
            ]);
            throw $error;
        }
    }
}
