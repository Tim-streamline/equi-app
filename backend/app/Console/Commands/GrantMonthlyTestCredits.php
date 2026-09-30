<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\MonthlyTestCredits;
use Illuminate\Console\Command;

class GrantMonthlyTestCredits extends Command
{
    protected $signature = 'credits:grant-test-monthly';
    protected $description = 'Give active local/staging accounts seven test credits once per calendar month';

    public function handle(MonthlyTestCredits $credits): int
    {
        if (! app()->environment(['local', 'staging'])) {
            $this->info('Monthly test credits are disabled outside local/staging.');
            return self::SUCCESS;
        }
        $granted = 0;
        $failures = 0;
        User::whereNull('disabled_at')->chunkById(100, function ($users) use ($credits, &$granted, &$failures) {
            foreach ($users as $user) {
                try { $granted += (int) $credits->grant($user); }
                catch (\Throwable $error) { report($error); $failures++; }
            }
        });
        $this->info("Granted seven test credits to {$granted} accounts.");
        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
