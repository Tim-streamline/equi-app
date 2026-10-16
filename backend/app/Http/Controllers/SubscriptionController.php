<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\SubscriptionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function show(Request $request, SubscriptionAccess $access)
    {
        $subscription = $access->active($request->attributes->get('powersync_user_id'));

        return response()->json(['subscribed' => (bool) $subscription, 'paidThrough' => $subscription?->paid_through,
            'checkoutAvailable' => false]);
    }

    public function renew(Request $request, SubscriptionAccess $access)
    {
        $userId = $request->attributes->get('powersync_user_id');
        $subscription = DB::transaction(function () use ($userId, $access) {
            // Serialize retries, including accounts that have no subscription yet.
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            if ($subscription = $access->active($userId)) {
                return $subscription;
            }

            $plan = Plan::firstOrCreate(['slug' => 'subscription'], [
                'name' => 'EquiApp abonnement', 'label' => 'Abonnement',
                'price_cents' => 1200, 'currency' => 'EUR', 'interval' => 'monthly',
            ]);
            $subscription = Subscription::where('user_id', $userId)->latest()->first()
                ?? new Subscription(['user_id' => $userId]);
            // Direct activation until checkout is implemented; no payment is recorded.
            $subscription->fill([
                'plan_id' => $plan->id, 'status' => 'active',
                'price_cents' => $plan->price_cents, 'currency' => $plan->currency,
                'interval' => $plan->interval, 'started_at' => today(), 'started_label' => null,
                'paid_through' => null, 'renews_at' => null, 'renews_label' => null,
                'cancelled_at' => null, 'cancel_requested_at' => null, 'ended_at' => null,
            ])->save();

            return $subscription;
        });

        return response()->json(['subscribed' => true, 'paidThrough' => $subscription->paid_through]);
    }
}
