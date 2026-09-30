<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Temporary test allowance, separate from paid Basic renewals and their cap. */
class MonthlyTestCredits
{
    public function grant(User $user): bool
    {
        if (! app()->environment(['local', 'staging']) || $user->disabled_at) {
            return false;
        }

        $ledger = app(CreditLedger::class);
        return $ledger->locked($user, function () use ($user, $ledger) {
            // Recheck account status under the same lock used for all credit changes.
            if ($user->fresh()->disabled_at) return false;
            $reason = 'Monthly test credits: '.now()->format('Y-m');
            if (DB::table('credit_transactions')->where('user_id', $user->id)
                ->where('type', 'promotional')->where('reason', $reason)->exists()) return false;

            $ledger->grant($user, 7, 'promotional', reason: $reason);
            return true;
        });
    }
}
