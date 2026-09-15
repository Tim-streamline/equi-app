<?php

namespace App\Console\Commands;

use App\Models\Protocol;
use App\Support\ProtocolPhaseReminders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SendProtocolPhaseReminders extends Command
{
    protected $signature = 'protocols:send-phase-reminders';

    protected $description = 'Send grouped reminders when protocol phases become available';

    public function handle(ProtocolPhaseReminders $reminders): int
    {
        $failed = false;
        Protocol::where('status', 'active')->whereNotNull('published_at')->whereNotNull('started_at')
            ->chunkById(100, function ($protocols) use ($reminders, &$failed) {
                foreach ($protocols as $protocol) {
                    try {
                        $reminders->send($protocol, CarbonImmutable::now());
                    } catch (Throwable $exception) {
                        $failed = true;
                        report($exception);
                        $this->error('Reminder failed for protocol '.$protocol->id.'; a later run will retry.');
                    }
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
