<?php

namespace App\Console\Commands;

use App\Models\Protocol;
use App\Models\User;
use App\Support\BrowserReminders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendBrowserReminders extends Command
{
    protected $signature = 'notifications:send-browser-reminders';

    protected $description = 'Send protocol and credit reminders to subscribed browsers';

    public function handle(BrowserReminders $reminders): int
    {
        if (! config('webpush.public_key') || ! config('webpush.private_key') || ! config('webpush.subject')) {
            return self::SUCCESS;
        }
        $failed = false;
        Protocol::where('status', 'active')->whereNotNull('published_at')->whereNotNull('started_at')
            ->whereHas('horse', fn ($q) => $q->whereIn('owner_id', DB::table('web_push_subscriptions')->select('user_id')))
            ->chunkById(100, function ($protocols) use ($reminders, &$failed) {
                foreach ($protocols as $protocol) {
                    try {
                        $reminders->protocol($protocol, CarbonImmutable::now());
                    } catch (\Throwable $error) {
                        report($error);
                        $failed = true;
                    }
                }
            });
        User::where('notifications_on', true)->whereNull('disabled_at')->whereIn('id', DB::table('web_push_subscriptions')->select('user_id'))
            ->chunkById(100, function ($users) use ($reminders, &$failed) {
                foreach ($users as $user) {
                    try {
                        $reminders->credits($user);
                    } catch (\Throwable $error) {
                        report($error);
                        $failed = true;
                    }
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
