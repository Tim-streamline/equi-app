<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\PlusPage;
use App\Models\User;
use App\Support\ProtocolAccess;
use App\Support\ProtocolPurchases;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\HasSubscription;
use Tests\TestCase;

class PlusIntakeAccessTest extends TestCase
{
    use HasSubscription, RefreshDatabase;

    public function test_subscription_alone_does_not_create_a_protocol_or_intake(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $this->assertFalse(app(ProtocolAccess::class)->state($user->id, $horse->id)['intakeAvailable']);
        $this->expectException(HttpException::class);
        app(ProtocolAccess::class)->start($user->id, $horse->id);
    }

    public function test_each_verified_purchase_creates_one_pair_and_retries_never_duplicate_or_restore_deleted_pairs(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user);
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $purchases = app(ProtocolPurchases::class);
        $order = $purchases->prepare($user->id, $horse->id, (string) Str::uuid());
        $this->assertDatabaseCount('protocols', 0);
        $this->assertDatabaseCount('intake_bookings', 0);
        $subscription->update(['paid_through' => now()->subDay()]);
        $protocol = $purchases->fulfillVerifiedPayment($order->id, 'verified-1', 14700, 'EUR');
        $this->assertSame($horse->id, $protocol->horse_id);
        $this->assertSame($protocol->id, $protocol->intake->protocol_id);
        $this->assertSame($protocol->id, $purchases->fulfillVerifiedPayment($order->id, 'verified-1', 14700, 'EUR')->id);
        $this->assertDatabaseCount('protocols', 1);
        $this->assertDatabaseCount('intake_bookings', 1);
        $protocol->delete();
        $this->assertNull($purchases->fulfillVerifiedPayment($order->id, 'verified-1', 14700, 'EUR'));
        $this->assertDatabaseCount('protocols', 0);
    }

    public function test_price_change_only_affects_new_quotes_and_old_paid_history_does_not_hide_a_new_intake(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $purchases = app(ProtocolPurchases::class);
        $key = (string) Str::uuid();
        $old = $purchases->prepare($user->id, $horse->id, $key);
        PlusPage::create(['id' => PlusPage::PAGE_ID, 'content' => PlusPage::defaults(), 'protocol_price_cents' => 19900]);
        $this->assertSame(14700, $purchases->prepare($user->id, $horse->id, $key)->amount_cents);
        $first = $purchases->fulfillVerifiedPayment($old->id, 'verified-old', 14700, 'EUR');
        $first->update(['status' => 'completed']);
        $first->intake->update(['submitted_at' => now()]);
        $new = $purchases->prepare($user->id, $horse->id, (string) Str::uuid());
        $this->assertSame(19900, $new->amount_cents);
        $second = $purchases->fulfillVerifiedPayment($new->id, 'verified-new', 19900, 'EUR');
        $this->assertSame($second->intake->id, app(ProtocolAccess::class)->start($user->id, $horse->id)->id);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_wrong_payment_amount_cannot_fulfill_a_protocol(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $purchase = app(ProtocolPurchases::class)->prepare($user->id, $horse->id, (string) Str::uuid());
        try {
            app(ProtocolPurchases::class)->fulfillVerifiedPayment($purchase->id, 'wrong', 1, 'EUR');
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('protocols', 0);
    }

    public function test_purchases_are_bound_to_the_owner_horse_and_request_key(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $other = Horse::create(['owner_id' => $user->id, 'name' => 'Luna']);
        $foreign = Horse::create(['owner_id' => User::factory()->create()->id, 'name' => 'Other']);
        $purchases = app(ProtocolPurchases::class);
        try {
            $purchases->prepare($user->id, $foreign->id, (string) Str::uuid());
            $this->fail();
        } catch (ModelNotFoundException) {
        }
        $key = (string) Str::uuid();
        $first = $purchases->prepare($user->id, $horse->id, $key);
        try {
            $purchases->prepare($user->id, $other->id, $key);
            $this->fail();
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $second = $purchases->prepare($user->id, $other->id, (string) Str::uuid());
        $purchases->fulfillVerifiedPayment($first->id, 'nova', 14700, 'EUR');
        $protocol = $purchases->fulfillVerifiedPayment($second->id, 'luna', 14700, 'EUR');
        $this->assertSame($other->id, $protocol->intake->horse_id);
        $this->assertDatabaseCount('protocols', 2);
        $this->assertDatabaseCount('intake_bookings', 2);
    }
}
