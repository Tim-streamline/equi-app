<?php
namespace App\Contracts;

interface CreditPaymentGateway
{
    /** Create a checkout using the immutable order price, currency and id as provider idempotency key. */
    public function checkout(object $order): array;
    /** Retrieve payment directly from the provider, never from client-supplied status. */
    public function refresh(object $order): void;
    public function configured(): bool;
}
