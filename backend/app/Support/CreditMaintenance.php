<?php
namespace App\Support;

use App\Models\{Subscription, User};
use Illuminate\Support\Facades\DB;

class CreditMaintenance
{
    public function run(User $user): void
    {
        $ledger = app(CreditLedger::class);
        $ledger->locked($user, function () use ($user, $ledger) {
            Subscription::where('user_id', $user->id)->whereHas('plan', fn ($q) => $q->where('slug', 'basic'))->whereNotNull('cancel_requested_at')->where('paid_through', '<=', now())->whereNull('ended_at')
                ->update(['status' => 'cancelled', 'ended_at' => DB::raw('paid_through')]);
            $ledger->expire($user);
        });
    }
}
