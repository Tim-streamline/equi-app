<?php

namespace App\Console\Commands;

use App\Models\IntakeBooking;
use App\Models\Protocol;
use App\Support\CustomerNotificationMail;
use Illuminate\Console\Command;
use Throwable;

class SendCustomerNotificationMails extends Command
{
    protected $signature = 'mail:send-customer-notifications';

    protected $description = 'Email customers once when their intake is received and once when their recovery plan is published';

    public function handle(CustomerNotificationMail $sender): int
    {
        // Keep pending emails intact while the TransIP mailbox is being configured.
        if (config('mail.default') === 'smtp'
            && config('mail.mailers.smtp.host') === 'smtp.transip.email'
            && (blank(config('mail.mailers.smtp.username')) || blank(config('mail.mailers.smtp.password')))) {
            $this->warn('TransIP SMTP-wachtwoord of gebruikersnaam ontbreekt. Klantmails blijven in afwachting.');

            return self::FAILURE;
        }

        $failed = false;
        $each = function ($records, callable $send, string $label) use (&$failed) {
            foreach ($records as $record) {
                try {
                    $send($record->id);
                } catch (Throwable $error) {
                    report($error);
                    $this->error($label.' mislukt voor '.$record->id);
                    $failed = true;
                }
            }
        };
        IntakeBooking::where('confirmation_email_status', 'pending')->where('intake_status', 'submitted')->whereNotNull('submitted_at')
            ->chunkById(50, fn ($bookings) => $each($bookings, $sender->sendIntakeReceived(...), 'Intakebevestiging'));
        CustomerNotificationMail::publishedPlanIsDue(Protocol::where('published_email_status', 'pending'))
            ->chunkById(50, fn ($protocols) => $each($protocols, $sender->sendRecoveryPlanPublished(...), 'Herstelplanmail'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
