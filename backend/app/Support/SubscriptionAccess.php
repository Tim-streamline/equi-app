<?php

namespace App\Support;

use App\Models\Subscription;

/** One account subscription, independent of separately purchased protocols. */
class SubscriptionAccess
{
    public function active(string $userId): ?Subscription
    {
        return Subscription::where('user_id', $userId)->where('status', 'active')->whereHas('plan', fn ($q) => $q->where('slug', '!=', 'free'))
            ->where(fn ($q) => $q->whereNull('started_at')->orWhereDate('started_at', '<=', today()))
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('paid_through')->orWhere('paid_through', '>', now()))
            ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>', now()))
            ->orderByDesc('paid_through')->first();
    }

    public function requireActive(string $userId): void
    {
        abort_unless($this->active($userId), 402, 'Verleng je abonnement om EquiApp te gebruiken.');
    }
}
