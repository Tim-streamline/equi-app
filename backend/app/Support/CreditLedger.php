<?php

namespace App\Support;

use App\Models\{Subscription, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** All changes serialize on the account row; balances are sums of immutable ledger entries. */
class CreditLedger
{
    public function settings(): object
    {
        return DB::table('credit_settings')->find(1);
    }

    public function basic(User $user): ?Subscription
    {
        return $user->subscriptions()->with('plan')->where('status', 'active')
            ->whereHas('plan', fn ($q) => $q->where('slug', 'basic'))
            ->where(fn ($q) => $q->whereNull('started_at')->orWhereDate('started_at', '<=', today()))
            ->whereNull('ended_at')->where('paid_through', '>', now())
            ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>', now()))
            ->orderByDesc('paid_through')->first();
    }

    public function locked(User $user, callable $work): mixed
    {
        return DB::transaction(function () use ($user, $work) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            return $work();
        }, 3);
    }

    public function grants(User $user): \Illuminate\Support\Collection
    {
        return DB::table('credit_grants as g')->where('g.user_id', $user->id)
            ->select('g.*')->selectSub(DB::table('credit_transactions as t')->selectRaw('COALESCE(SUM(t.amount), 0)')->whereColumn('t.grant_id', 'g.id'), 'remaining')
            ->get()->map(function ($g) {
                $g->remaining = (int) $g->remaining;
                if ($g->source === 'membership') {
                    $subscription = Subscription::find($g->subscription_id);
                    $dates = array_filter([$subscription?->cancel_requested_at ? $subscription->paid_through : null, $subscription?->ended_at, $subscription?->cancelled_at]);
                    $g->expires_at = $dates ? min(array_map(fn ($d) => CarbonImmutable::parse($d)->toDateTimeString(), $dates)) : null;
                    if (! $subscription || $subscription->status === 'cancelled' || $subscription->plan?->slug !== 'basic') $g->expires_at = now()->toDateTimeString();
                }
                return $g;
            })->filter(fn ($g) => $g->remaining > 0)
            ->sortBy(fn ($g) => ($g->expires_at ?: '9999-12-31').' '.$g->created_at.' '.$g->id)->values();
    }

    public function expire(User $user): void
    {
        foreach ($this->grants($user) as $g) {
            if ($g->expires_at && CarbonImmutable::parse($g->expires_at)->lte(now())) {
                $this->entry($user, $g, -$g->remaining, 'expired', 'Credits verlopen');
            }
        }
    }

    public function summary(User $user): array
    {
        return $this->locked($user, function () use ($user) {
            $this->expire($user);
            $grants = $this->grants($user); $basic = $this->basic($user); $settings = $this->settings();
            return [
                'balance' => (int) $grants->sum('remaining'),
                'membership' => (int) $grants->where('source', 'membership')->sum('remaining'),
                'purchased' => (int) $grants->where('source', 'purchased')->sum('remaining'),
                'other' => (int) $grants->whereNotIn('source', ['membership', 'purchased'])->sum('remaining'),
                'membershipCap' => (int) $settings->membership_cap, 'monthlyCredits' => (int) $settings->monthly_credits,
                'expiring' => $grants->where('source', 'purchased')->filter(fn ($g) => $g->expires_at && CarbonImmutable::parse($g->expires_at)->lte(now()->addDays(30)))->map(fn ($g) => ['credits' => $g->remaining, 'date' => $g->expires_at, 'urgent' => CarbonImmutable::parse($g->expires_at)->lte(now()->addDays(7))])->values(),
                'nextExpiry' => $grants->first(fn ($g) => $g->source === 'purchased' && $g->expires_at)?->expires_at,
                'nextRenewal' => $basic && ! $basic->cancel_requested_at && $basic->renews_at?->isFuture() ? $basic->renews_at?->toIso8601String() : null,
                'endsAt' => $basic?->cancel_requested_at ? $basic->paid_through?->toIso8601String() : null,
                'hasBasic' => (bool) $basic,
                'history' => DB::table('credit_transactions')->where('user_id', $user->id)->orderByDesc('created_at')->orderBy('id')->get(),
            ];
        });
    }

    /** Called inside the same user lock and transaction as the permanent unlock. */
    public function spend(User $user, int $cost, string $itemId): void
    {
        $this->expire($user); $grants = $this->grants($user);
        abort_unless($cost >= 0 && $grants->sum('remaining') >= $cost, 422, 'Je hebt onvoldoende credits voor dit item.');
        foreach ($grants as $g) {
            if ($cost === 0) break;
            $amount = min($cost, $g->remaining);
            $this->entry($user, $g, -$amount, 'spent', \App\Models\LibraryItem::find($itemId)?->title ?? 'Bibliotheekitem ontgrendeld', $itemId);
            $cost -= $amount;
        }
    }

    public function grant(User $user, int $amount, string $source, ?string $expiry = null, ?string $subscriptionId = null, ?string $orderId = null, string $reason = ''): ?string
    {
        // Internal ledger primitive; the temporary top-up endpoint fixes its own amount and source.
        if ($amount <= 0) return null;
        $id = (string) Str::uuid();
        DB::table('credit_grants')->insert(['id' => $id, 'user_id' => $user->id, 'source' => $source, 'expires_at' => $expiry, 'subscription_id' => $subscriptionId, 'order_id' => $orderId, 'created_at' => now(), 'updated_at' => now()]);
        $g = DB::table('credit_grants')->find($id);
        $this->entry($user, $g, $amount, $source, match ($source) { 'membership' => 'Basic verlenging', 'purchased' => 'Credits bijgekocht', 'promotional' => 'Tijdelijke credits toegevoegd', default => 'Creditcorrectie' }, reason: $reason);
        return $id;
    }

    public function entry(User $user, object $g, int $amount, string $type, string $description, ?string $itemId = null, string $reason = ''): void
    {
        DB::table('credit_transactions')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'grant_id' => $g->id,
            'amount' => $amount, 'type' => $type, 'source' => $g->source, 'expires_at' => $g->expires_at,
            'payment_id' => $g->order_id ? DB::table('credit_orders')->where('id', $g->order_id)->value('payment_id') : null,
            'item_id' => $itemId, 'description' => $description, 'reason' => $reason ?: null, 'created_at' => now(),
        ]);
    }

    /** Only a server-verified payment may enter here; browser redirects are never payment evidence. */
    public function settle(string $orderId, string $paymentId, CarbonImmutable $paidAt): void
    {
        $order = DB::table('credit_orders')->find($orderId); abort_unless($order, 404);
        $user = User::findOrFail($order->user_id);
        $this->locked($user, function () use ($user, $orderId, $paymentId, $paidAt) {
            $order = DB::table('credit_orders')->where('id', $orderId)->lockForUpdate()->first();
            abort_unless($order->payment_id === $paymentId, 409);
            if ($order->paid_at || $order->reversed_at) return;
            $amount = $order->credits; $expiry = null;
            if ($order->kind === 'membership') {
                $s = Subscription::whereKey($order->subscription_id)->lockForUpdate()->firstOrFail();
                // No grants after cancellation, including a provider retry for a new period.
                abort_unless($s->plan->slug === 'basic' && ! $s->cancel_requested_at && ! $s->ended_at && $order->period_end, 409, 'Basic is niet meer verlengbaar.');
                $end = CarbonImmutable::parse($order->period_end);
                if (! $s->paid_through || $end->gt($s->paid_through)) {
                    $s->update(['paid_through' => $end, 'renews_at' => $end, 'status' => 'active']);
                }
                $this->expire($user);
                $amount = min($amount, max(0, $this->settings()->membership_cap - $this->grants($user)->where('source', 'membership')->sum('remaining')));
            } else {
                $expiry = $paidAt->addMonthsNoOverflow($order->validity_months)->toDateTimeString();
                $this->expire($user);
            }
            DB::table('credit_orders')->where('id', $orderId)->update(['status' => 'paid', 'paid_at' => $paidAt, 'updated_at' => now()]);
            $this->grant($user, (int) $amount, $order->kind, $expiry, $order->subscription_id, $orderId, 'Verified payment '.$paymentId);
            $this->expire($user);
        });
    }

    /** Reverse only unspent credits. Spent credits are flagged for support; permanent unlocks remain. */
    public function reverse(string $orderId, string $reason): void
    {
        $order = DB::table('credit_orders')->find($orderId); abort_unless($order, 404);
        $user = User::findOrFail($order->user_id);
        $this->locked($user, function () use ($user, $orderId, $reason) {
            $order = DB::table('credit_orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order->reversed_at) return;
            $this->expire($user);
            foreach ($this->grants($user)->where('order_id', $orderId) as $g) $this->entry($user, $g, -$g->remaining, 'refund', 'Betaling teruggedraaid', reason: $reason);
            $grant = DB::table('credit_grants')->where('order_id', $orderId)->first();
            $spent = $grant ? -(int) DB::table('credit_transactions')->where('grant_id', $grant->id)->where('type', 'spent')->sum('amount') : 0;
            DB::table('credit_orders')->where('id', $orderId)->update(['status' => 'reversed', 'reversed_at' => now(), 'spent_before_reversal' => $spent, 'updated_at' => now()]);
        });
    }
}
