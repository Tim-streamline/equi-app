<?php

namespace Tests\Concerns;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

trait HasSubscription
{
    protected function subscribe(User $user): Subscription
    {
        $plan = Plan::firstOrCreate(['slug' => 'subscription'], ['name' => 'EquiApp abonnement', 'label' => 'Abonnement', 'price_cents' => 1200, 'currency' => 'EUR', 'interval' => 'monthly']);

        return Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active', 'price_cents' => 1200, 'currency' => 'EUR', 'interval' => 'monthly', 'paid_through' => now()->addMonth()]);
    }
}
