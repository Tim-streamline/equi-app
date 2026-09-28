<?php
namespace App\Support;
use App\Contracts\CreditPaymentGateway;

class UnconfiguredCreditPayments implements CreditPaymentGateway
{
    public function configured(): bool { return false; }
    public function checkout(object $order): array { abort(503, 'Credits bijkopen is nog niet beschikbaar.'); }
    public function refresh(object $order): void {}
}
