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
        CustomerNotificationMail::skipCancelledConfirmations();
        // Keep pending emails intact (nothing is claimed) until a real mail transport is configured.
        if ($hold = $sender->deliveryHold()) {
            $this->warn($hold);

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
        CustomerNotificationMail::intakeIsDue(IntakeBooking::where('confirmation_email_status', 'pending'))
            ->chunkById(50, fn ($bookings) => $each($bookings, $sender->sendIntakeReceived(...), 'Intakebevestiging'));
        CustomerNotificationMail::publishedPlanIsDue(Protocol::where('published_email_status', 'pending'))
            ->chunkById(50, fn ($protocols) => $each($protocols, $sender->sendRecoveryPlanPublished(...), 'Herstelplanmail'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
