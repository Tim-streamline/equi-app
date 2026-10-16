<?php

namespace App\Support;

use App\Models\Subscription;

/** @deprecated Use ProtocolAccess. Legacy grants do not authorize new purchases. */
class PlusIntakeAccess extends ProtocolAccess
{
    public function subscription(string $userId): ?Subscription
    {
        return app(SubscriptionAccess::class)->active($userId);
    }
}
