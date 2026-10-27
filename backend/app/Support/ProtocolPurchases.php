<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\PlusPage;
use App\Models\Protocol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProtocolPurchases
{
    /** Temporary direct creation while payment is not integrated. Never records a payment. */
    public function createWithoutPayment(string $userId, string $horseId): Protocol
    {
        app(SubscriptionAccess::class)->requireActive($userId);

        return DB::transaction(function () use ($userId, $horseId) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            LibrarySyncLock::acquire();
            Horse::whereKey($horseId)->where('owner_id', $userId)->where('status', 'active')->lockForUpdate()->firstOrFail();
            // Repeat clicks and retries after a lost response reuse the unfinished pair.
            $existing = Protocol::where('horse_id', $horseId)->whereNotIn('status', ['completed', 'archived'])->oldest()->first();
            if ($existing) {
                abort_unless($existing->intake && $existing->intake->user_id === $userId && $existing->intake->status !== 'cancelled',
                    409, 'Dit paard heeft al een protocol. Neem contact op met je behandelaar.');

                return $existing;
            }

            // Protocol::save creates the associated intake in the same transaction.
            return Protocol::create(['horse_id' => $horseId, 'title' => 'Persoonlijk protocol', 'status' => 'draft']);
        });
    }

    /** Snapshot the configured price once. A retry keeps the original quote. */
    public function prepare(string $userId, string $horseId, string $requestKey): object
    {
        app(SubscriptionAccess::class)->requireActive($userId);
        abort_unless(Str::isUuid($requestKey), 422);

        return DB::transaction(function () use ($userId, $horseId, $requestKey) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            LibrarySyncLock::acquire();
            $existing = DB::table('protocol_purchases')->where('user_id', $userId)->where('request_key', $requestKey)->first();
            if ($existing) {
                abort_unless($existing->horse_id === $horseId, 409);

                return $existing;
            }
            Horse::whereKey($horseId)->where('owner_id', $userId)->where('status', 'active')->firstOrFail();
            $id = (string) Str::uuid();
            DB::table('protocol_purchases')->insert(['id' => $id, 'user_id' => $userId, 'horse_id' => $horseId,
                'request_key' => $requestKey, 'amount_cents' => PlusPage::current()->protocol_price_cents ?? 14700,
                'currency' => 'EUR', 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('protocol_purchases')->find($id);
        });
    }

    /** Internal boundary: only a provider adapter that verified payment may call this. */
    public function fulfillVerifiedPayment(string $purchaseId, string $paymentReference, int $amountCents, string $currency): ?Protocol
    {
        abort_unless($paymentReference !== '', 422);
        $purchase = DB::table('protocol_purchases')->find($purchaseId);
        abort_unless($purchase, 404);

        return DB::transaction(function () use ($purchaseId, $purchase, $paymentReference, $amountCents, $currency) {
            User::whereKey($purchase->user_id)->lockForUpdate()->firstOrFail();
            LibrarySyncLock::acquire();
            $receipt = DB::table('protocol_purchases')->where('id', $purchaseId)->lockForUpdate()->first();
            abort_unless((int) $receipt->amount_cents === $amountCents && $receipt->currency === $currency, 409);
            if ($receipt->fulfilled_at) {
                abort_unless($receipt->payment_reference === $paymentReference, 409);

                return $receipt->protocol_id ? Protocol::find($receipt->protocol_id) : null;
            }
            Horse::whereKey($receipt->horse_id)->where('owner_id', $receipt->user_id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            // Fulfill a paid order even when the subscription expired during checkout.
            $protocol = Protocol::create(['horse_id' => $receipt->horse_id, 'title' => 'Persoonlijk protocol', 'status' => 'draft']);
            DB::table('protocol_purchases')->where('id', $purchaseId)->update(['protocol_id' => $protocol->id,
                'payment_reference' => $paymentReference, 'fulfilled_at' => now(), 'updated_at' => now()]);

            return $protocol;
        });
    }
}
