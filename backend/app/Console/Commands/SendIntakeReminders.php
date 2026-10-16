<?php

namespace App\Console\Commands;

use App\Models\IntakeBooking;
use App\Support\IntakeReminders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendIntakeReminders extends Command
{
    protected $signature = 'intakes:send-reminders';

    protected $description = 'Send intake reminders after three days without activity';

    public function handle(IntakeReminders $reminders): int
    {
        $sent = 0;
        IntakeBooking::whereNull('submitted_at')->where('status', '!=', 'cancelled')->where('intake_status', '!=', 'submitted')->chunkById(100, function ($bookings) use ($reminders, &$sent) {
            foreach ($bookings as $booking) {
                $sent += $reminders->send($booking, CarbonImmutable::now());
            }
        });
        $this->info("Sent {$sent} intake reminders.");

        return self::SUCCESS;
    }
}
