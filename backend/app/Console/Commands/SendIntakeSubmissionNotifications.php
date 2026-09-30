<?php

namespace App\Console\Commands;

use App\Models\IntakeBooking;
use App\Support\IntakeSubmissionMail;
use Illuminate\Console\Command;
use Throwable;

class SendIntakeSubmissionNotifications extends Command
{
    protected $signature = 'intakes:send-submission-notifications';

    protected $description = 'Notify the therapist once for each newly submitted protocol intake';

    public function handle(IntakeSubmissionMail $sender): int
    {
        // Keep pending notifications intact while the TransIP mailbox is being configured.
        if (config('mail.default') === 'smtp'
            && config('mail.mailers.smtp.host') === 'smtp.transip.email'
            && (blank(config('mail.mailers.smtp.username')) || blank(config('mail.mailers.smtp.password')))) {
            $this->warn('TransIP SMTP-wachtwoord of gebruikersnaam ontbreekt. Intakemeldingen blijven in afwachting.');

            return self::FAILURE;
        }

        $failed = false;
        IntakeBooking::where('submission_email_status', 'pending')->where('intake_status', 'submitted')
            ->whereNotNull('submitted_at')->chunkById(50, function ($bookings) use ($sender, &$failed) {
                foreach ($bookings as $booking) {
                    try {
                        $sender->send($booking->id);
                    } catch (Throwable $error) {
                        report($error);
                        $this->error('Intakemelding mislukt voor intake '.$booking->id);
                        $failed = true;
                    }
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
