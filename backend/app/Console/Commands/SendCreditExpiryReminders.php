<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\CreditExpiryReminders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendCreditExpiryReminders extends Command
{
    protected $signature = 'credits:send-expiry-reminders';
    protected $description = 'Send 30-day and 7-day purchased-credit expiry reminders and check push receipts';

    public function handle(CreditExpiryReminders $reminders): int
    {
        $failed = false;
        try { $reminders->receipts(); } catch (\Throwable $error) { report($error); $failed = true; }
        User::where('notifications_on', true)->whereNull('disabled_at')
            ->whereIn('id', DB::table('credit_grants')->select('user_id')->where('source', 'purchased')
                ->where('expires_at', '>', now())->where('expires_at', '<=', now()->addDays(30)))
            ->chunkById(100, function ($users) use ($reminders, &$failed) {
                foreach ($users as $user) {
                    try { $reminders->send($user); } catch (\Throwable $error) { report($error); $failed = true; }
                }
            });
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
