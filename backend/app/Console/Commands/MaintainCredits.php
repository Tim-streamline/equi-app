<?php
namespace App\Console\Commands;
use App\Models\User;
use App\Support\CreditMaintenance;
use Illuminate\Console\Command;

class MaintainCredits extends Command
{
    protected $signature = 'credits:maintain';
    protected $description = 'Expire credits and finish cancelled subscriptions at the end of their paid period';
    public function handle(CreditMaintenance $maintenance): int
    {
        $failures = 0;
        User::whereHas('subscriptions')->orWhereIn('id', \Illuminate\Support\Facades\DB::table('credit_grants')->select('user_id'))->chunkById(100, function ($users) use ($maintenance, &$failures) {
            foreach ($users as $user) {
                try { $maintenance->run($user); } catch (\Throwable $error) { report($error); $failures++; }
            }
        });
        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
