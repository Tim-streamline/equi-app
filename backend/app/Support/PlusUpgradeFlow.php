<?php

namespace App\Support;

/** @deprecated The bundled subscription/intake payment flow must not activate either product. */
class PlusUpgradeFlow
{
    public function choose(string $userId, string $horseId): never
    {
        abort(410, 'Koop een protocol afzonderlijk van je abonnement.');
    }

    public function applyVerifiedPayment(string $upgradeId, string $status, int $amountCents, string $currency): never
    {
        throw new \LogicException('Use separate subscription renewal and ProtocolPurchases fulfillment.');
    }
}
