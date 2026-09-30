<?php
namespace Tests\Feature;
use App\Contracts\CreditPaymentGateway;
use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\{LibraryItem, Plan, Subscription, User};
use App\Support\{CreditLedger, CreditMaintenance};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreditLedgerTest extends TestCase
{
    use RefreshDatabase;
    private User $user;
    private CreditLedger $ledger;
    protected function setUp(): void
    {
        parent::setUp(); $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00'));
        $this->user = User::factory()->create(); $this->ledger = app(CreditLedger::class); $this->customer($this->user);
    }
    private function customer(User $user): void
    {
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($user->id) {
            public function __construct(private string $id) {}
            public function handle($request, $next) { $request->attributes->set('powersync_user_id', $this->id); return $next($request); }
        });
    }
    private function basic(): Subscription
    {
        $plan = Plan::firstOrCreate(['slug' => 'basic'], ['label' => 'Basic', 'name' => 'Basic', 'price_cents' => 495, 'interval' => 'monthly']);
        return Subscription::create(['user_id' => $this->user->id, 'plan_id' => $plan->id, 'status' => 'active', 'price_cents' => 495, 'interval' => 'monthly', 'paid_through' => now()->addMonth(), 'renews_at' => now()->addMonth()]);
    }
    private function order(string $kind = 'purchased', int $credits = 10, ?Subscription $s = null): object
    {
        $id = (string) Str::uuid();
        DB::table('credit_orders')->insert(['id' => $id, 'user_id' => $this->user->id, 'subscription_id' => $s?->id, 'kind' => $kind, 'credits' => $credits, 'price_cents' => 495, 'currency' => 'EUR', 'validity_months' => 6, 'payment_id' => 'test_'.$id, 'request_key' => (string) Str::uuid(), 'period_end' => $s ? now()->addMonths(2) : null, 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('credit_orders')->find($id);
    }
    private function paid(object $o): void { $this->ledger->settle($o->id, $o->payment_id, CarbonImmutable::now()); }
    private function item(int $cost): LibraryItem { return LibraryItem::create(['title' => 'Lesson', 'slug' => (string) Str::uuid(), 'format' => 'article', 'credit_cost' => $cost, 'body' => 'Paid content', 'published_at' => now()->subDay()]); }
    public function test_only_verified_payment_grants_once_and_dates_or_failed_payments_do_not_grant(): void
    {
        $s = $this->basic(); $o = $this->order('membership', 7, $s);
        DB::table('credit_orders')->where('id', $o->id)->update(['status' => 'failed']);
        $s->update(['renews_at' => now()->addMonths(4)]); app(CreditMaintenance::class)->run($this->user);
        $this->getJson('/api/library/credits')->assertOk()->assertJsonPath('balance', 0);
        $this->paid($o); $this->paid($o);
        $this->getJson('/api/library/credits')->assertJsonPath('membership', 7)->assertJsonCount(1, 'history');
        $this->assertDatabaseHas('credit_transactions', ['payment_id' => $o->payment_id, 'amount' => 7, 'type' => 'membership']);
    }
    public function test_membership_cap_excludes_purchased_credits(): void
    {
        $s = $this->basic(); $this->ledger->grant($this->user, 24, 'membership', subscriptionId: $s->id);
        $this->paid($this->order('purchased', 40)); $o = $this->order('membership', 7, $s); $this->paid($o); $this->paid($o);
        $this->getJson('/api/library/credits')->assertJsonPath('membership', 28)->assertJsonPath('purchased', 40)->assertJsonPath('balance', 68);
    }
    public function test_spend_uses_earliest_expiry_and_unlock_survives_price_and_subscription_changes(): void
    {
        $s = $this->basic(); $s->update(['cancel_requested_at' => now()]); $this->ledger->grant($this->user, 4, 'membership', subscriptionId: $s->id);
        $early = $this->ledger->grant($this->user, 2, 'purchased', now()->addWeek()->toDateTimeString());
        $late = $this->ledger->grant($this->user, 5, 'purchased', now()->addMonths(4)->toDateTimeString());
        $item = $this->item(5); $url = '/api/library/'.$item->id.'/unlock';
        $this->postJson($url, ['credits' => 4])->assertStatus(409);
        $this->postJson($url, ['credits' => 5])->assertOk()->assertJsonPath('access.credits', 6)->assertJsonPath('body', 'Paid content');
        $this->postJson($url, ['credits' => 5])->assertOk(); $this->assertDatabaseCount('library_unlocks', 1);
        $this->assertSame(-2, (int) DB::table('credit_transactions')->where('grant_id', $early)->where('type', 'spent')->sum('amount'));
        $this->assertSame(0, (int) DB::table('credit_transactions')->where('grant_id', $late)->where('type', 'spent')->sum('amount'));
        $item->update(['credit_cost' => 999]); $s->update(['ended_at' => now(), 'status' => 'cancelled']);
        $this->postJson($url, ['credits' => 5])->assertOk()->assertJsonPath('canRead', true);
        $this->getJson('/api/library/credits')->assertJsonPath('balance', 5);
    }
    public function test_cancel_keeps_paid_access_until_end_and_expires_only_membership(): void
    {
        $s = $this->basic(); $this->ledger->grant($this->user, 7, 'membership', subscriptionId: $s->id); $this->paid($this->order('purchased', 9));
        $this->postJson('/api/library/credits/cancel-basic')->assertOk()->assertJsonPath('hasBasic', true)->assertJsonPath('balance', 16)->assertJsonPath('nextRenewal', null);
        $this->assertNotNull($s->fresh()->cancel_requested_at);
        $this->travel(32)->days(); app(CreditMaintenance::class)->run($this->user);
        $this->getJson('/api/library/credits')->assertJsonPath('hasBasic', false)->assertJsonPath('membership', 0)->assertJsonPath('purchased', 9);
        $this->assertDatabaseHas('credit_transactions', ['amount' => -7, 'type' => 'expired']);
    }
    public function test_purchase_expiry_is_six_months_and_read_expires_without_scheduler(): void
    {
        $o = $this->order(); $this->paid($o);
        $this->assertSame('2027-03-26 12:00:00', CarbonImmutable::parse(DB::table('credit_grants')->where('order_id', $o->id)->value('expires_at'))->toDateTimeString());
        $this->travel(7)->months(); $this->getJson('/api/library/credits')->assertJsonPath('balance', 0);
        $this->getJson('/api/library/credits')->assertJsonPath('balance', 0);
        $this->assertSame(1, DB::table('credit_transactions')->where('type', 'expired')->count());
    }
    public function test_refund_reverses_only_unspent_credits_and_flags_spent_without_revoking_unlock(): void
    {
        $o = $this->order(); $this->paid($o); $item = $this->item(3);
        $this->postJson('/api/library/'.$item->id.'/unlock', ['credits' => 3])->assertOk();
        $this->ledger->reverse($o->id, 'Chargeback'); $this->ledger->reverse($o->id, 'Duplicate');
        $this->assertDatabaseHas('credit_orders', ['id' => $o->id, 'status' => 'reversed', 'spent_before_reversal' => 3]);
        $this->assertDatabaseHas('credit_transactions', ['payment_id' => $o->payment_id, 'type' => 'refund', 'amount' => -7]);
        $this->getJson('/api/library/'.$item->id)->assertJsonPath('canRead', true);
        $this->getJson('/api/library/credits')->assertJsonPath('balance', 0);
    }
    public function test_balances_are_private_and_never_negative(): void
    {
        $this->paid($this->order()); $item = $this->item(11);
        $this->postJson('/api/library/'.$item->id.'/unlock', ['credits' => 11])->assertUnprocessable();
        $this->customer(User::factory()->create()); $this->getJson('/api/library/credits')->assertJsonPath('balance', 0)->assertJsonCount(0, 'history');
    }
    public function test_purchase_requires_basic_and_retry_uses_original_order_without_granting(): void
    {
        $this->app->instance(CreditPaymentGateway::class, new class implements CreditPaymentGateway {
            public function configured(): bool { return true; }
            public function refresh(object $order): void {}
            public function checkout(object $order): array { return ['checkoutUrl' => 'https://payments.example.test/'.$order->id]; }
        });
        $bundle = (string) Str::uuid(); DB::table('credit_bundles')->insert(['id' => $bundle, 'credits' => 5, 'price_cents' => 200, 'currency' => 'EUR', 'active' => true]);
        $body = ['bundleId' => $bundle, 'requestKey' => (string) Str::uuid()];
        $this->postJson('/api/library/credits/purchase', $body)->assertForbidden(); $this->basic();
        $first = $this->postJson('/api/library/credits/purchase', $body)->assertOk();
        DB::table('credit_bundles')->where('id', $bundle)->update(['price_cents' => 300]);
        $this->postJson('/api/library/credits/purchase', $body)->assertOk()->assertJsonPath('orderId', $first->json('orderId'));
        $this->assertDatabaseCount('credit_orders', 1); $this->assertDatabaseHas('credit_orders', ['price_cents' => 200]);
        $this->getJson('/api/library/credits/orders/'.$first->json('orderId'))->assertJsonPath('status', 'pending');
        $this->getJson('/api/library/credits')->assertJsonPath('balance', 0);
        $this->customer(User::factory()->create()); $this->getJson('/api/library/credits/orders/'.$first->json('orderId'))->assertNotFound();
    }
    public function test_unconfigured_payments_never_create_fake_success(): void
    {
        $this->basic(); $this->postJson('/api/library/credits/purchase', ['bundleId' => (string) Str::uuid(), 'requestKey' => (string) Str::uuid()])->assertStatus(503);
        $this->assertDatabaseCount('credit_orders', 0);
    }
    public function test_active_membership_carries_over_without_expiry_at_arbitrary_month_boundary(): void
    {
        $s = $this->basic(); $this->ledger->grant($this->user, 8, 'membership', subscriptionId: $s->id);
        $this->travel(32)->days(); app(CreditMaintenance::class)->run($this->user);
        $this->assertSame(8, $this->ledger->summary($this->user)['membership']);
        $this->paid($this->order('membership', 7, $s));
        $this->assertSame(15, $this->ledger->summary($this->user)['membership']);
    }
    public function test_full_cap_does_not_grant_and_cancellation_blocks_future_renewal_grants(): void
    {
        $s = $this->basic(); $this->ledger->grant($this->user, 28, 'membership', subscriptionId: $s->id);
        $o = $this->order('membership', 7, $s); $this->paid($o);
        $this->assertSame(28, $this->ledger->summary($this->user)['membership']);
        $this->assertDatabaseCount('credit_transactions', 1);
        $this->travel(1)->months(); $future = $this->order('membership', 7, $s);
        $s->update(['cancel_requested_at' => now()]);
        try { $this->paid($future); $this->fail('Cancelled renewal must be rejected'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
        $this->assertSame(28, $this->ledger->summary($this->user)['membership']);
    }
    public function test_settings_and_bundles_require_billing_role_and_preserve_existing_purchase_expiry(): void
    {
        $this->paid($this->order()); $expiry = DB::table('credit_grants')->value('expires_at');
        $admin = \App\Models\AdminUser::create(['name' => 'Billing', 'email' => 'billing@test.test', 'password' => 'password', 'role' => 'billing', 'active' => true]);
        $this->actingAs($admin, 'admin')->put('/admin/credits/settings', ['monthly_credits' => 8, 'membership_cap' => 32, 'purchase_validity_months' => 3])->assertSessionHasNoErrors();
        $this->assertEquals($expiry, DB::table('credit_grants')->value('expires_at'));
        $this->post('/admin/credits/bundles', ['credits' => 12, 'price_cents' => 600, 'currency' => 'EUR', 'active' => true, 'order' => 1])->assertSessionHasNoErrors();
        $this->getJson('/api/library/credits')->assertJsonPath('monthlyCredits', 8)->assertJsonPath('membershipCap', 32)->assertJsonPath('bundles.0.credits', 12);
        $admin->update(['role' => 'content_editor']);
        $this->put('/admin/credits/settings', ['monthly_credits' => 9, 'membership_cap' => 32, 'purchase_validity_months' => 3])->assertForbidden();
    }

    public function test_purchased_expiry_label_excludes_earlier_membership_end_and_history_names_unlocked_item(): void
    {
        $subscription = $this->basic(); $subscription->update(['cancel_requested_at' => now(), 'paid_through' => now()->addDay()]);
        $this->ledger->grant($this->user, 2, 'membership', subscriptionId: $subscription->id);
        $this->ledger->grant($this->user, 5, 'purchased', now()->addMonths(3)->toDateTimeString());
        $summary = $this->getJson('/api/library/credits')->assertOk();
        $this->assertTrue(CarbonImmutable::parse($summary->json('nextExpiry'))->equalTo(now()->addMonths(3)));
        $item = $this->item(1); $item->update(['title' => 'Ruwvoer analyseren']);
        $this->postJson('/api/library/'.$item->id.'/unlock', ['credits' => 1])->assertOk();
        $item->update(['title' => 'New title']);
        $this->assertDatabaseHas('credit_transactions', ['item_id' => $item->id, 'type' => 'spent', 'description' => 'Ruwvoer analyseren']);
    }

    public function test_temporary_top_up_adds_seven_once_per_click_and_can_unlock_content(): void
    {
        $key = (string) Str::uuid();
        $body = ['requestKey' => $key, 'amount' => 999];
        $this->postJson('/api/library/credits/temporary-top-up', $body)->assertOk()->assertJsonPath('balance', 7)->assertJsonPath('other', 7)->assertJsonPath('hasBasic', false);
        $this->postJson('/api/library/credits/temporary-top-up', $body)->assertOk()->assertJsonPath('balance', 7);
        $this->assertDatabaseCount('credit_grants', 1);
        $this->assertDatabaseCount('credit_orders', 0);
        $this->assertDatabaseHas('credit_transactions', ['user_id' => $this->user->id, 'amount' => 7, 'type' => 'promotional', 'payment_id' => null]);
        $item = $this->item(7);
        $this->postJson('/api/library/'.$item->id.'/unlock', ['credits' => 7])->assertOk()->assertJsonPath('canRead', true);
        $this->postJson('/api/library/credits/temporary-top-up', $body)->assertOk()->assertJsonPath('balance', 0);
        $this->postJson('/api/library/credits/temporary-top-up', ['requestKey' => (string) Str::uuid()])->assertOk()->assertJsonPath('balance', 7);
    }

    public function test_temporary_top_up_validates_keys_and_only_credits_the_authenticated_enabled_account(): void
    {
        $this->postJson('/api/library/credits/temporary-top-up', [])->assertUnprocessable();
        $other = User::factory()->create();
        $key = (string) Str::uuid();
        $this->postJson('/api/library/credits/temporary-top-up', ['requestKey' => $key, 'userId' => $other->id])->assertOk()->assertJsonPath('balance', 7);
        $this->assertSame(0, $this->ledger->summary($other)['balance']);
        $this->customer($other);
        $this->postJson('/api/library/credits/temporary-top-up', ['requestKey' => $key])->assertOk()->assertJsonPath('balance', 7);
        $other->update(['disabled_at' => now()]);
        $this->postJson('/api/library/credits/temporary-top-up', ['requestKey' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('credit_grants', 2);
    }
    public function test_summary_only_warns_for_credits_expiring_within_four_weeks(): void
    {
        $this->ledger->grant($this->user, 2, 'purchased', now()->addDays(28)->toDateTimeString());
        $this->ledger->grant($this->user, 3, 'purchased', now()->addDays(29)->toDateTimeString());
        $this->getJson('/api/library/credits')->assertOk()->assertJsonPath('balance', 5)
            ->assertJsonCount(1, 'expiring')->assertJsonPath('expiring.0.credits', 2);
        $subscription = $this->basic();
        $this->getJson('/api/library/credits')->assertJsonPath('nextRenewal', $subscription->renews_at->toIso8601String())->assertJsonPath('endsAt', null);
        $this->postJson('/api/library/credits/cancel-basic')->assertOk()->assertJsonPath('nextRenewal', null)
            ->assertJsonPath('endsAt', $subscription->paid_through->toIso8601String());
    }
}
