<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CreditLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlyTestCreditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_and_new_accounts_receive_seven_once_each_month_without_payment(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00:00'));
        $existing = User::factory()->create();
        $disabled = User::factory()->create(['disabled_at' => now()]);
        $this->app->instance('env', 'staging');
        $new = User::factory()->create();
        $ledger = app(CreditLedger::class);
        $this->assertSame(7, $ledger->summary($new)['balance']);
        $this->artisan('credits:grant-test-monthly')->assertSuccessful();
        $this->artisan('credits:grant-test-monthly')->assertSuccessful();
        $this->assertSame(7, $ledger->summary($existing)['balance']);
        $this->assertSame(7, $ledger->summary($new)['balance']);
        $this->assertSame(0, $ledger->summary($disabled)['balance']);
        $this->assertDatabaseCount('credit_transactions', 2);
        $this->assertDatabaseCount('credit_orders', 0);
        $this->assertDatabaseCount('subscriptions', 0);

        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:00:00'));
        $this->artisan('credits:grant-test-monthly')->assertSuccessful();
        $this->assertSame(14, $ledger->summary($existing)['balance']);
        $this->assertSame(14, $ledger->summary($new)['balance']);
        $this->assertDatabaseCount('credit_transactions', 4);
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:00:00'));
        $this->assertSame(21, $ledger->summary($existing)['balance']);
        $this->assertSame(0, DB::table('credit_transactions')->where('type', 'membership')->count());
    }

    public function test_production_never_grants_and_spending_does_not_reset_monthly_eligibility(): void
    {
        $this->app->instance('env', 'production');
        $user = User::factory()->create();
        $ledger = app(CreditLedger::class);
        $this->artisan('credits:grant-test-monthly')->assertSuccessful();
        $this->assertSame(0, $ledger->summary($user)['balance']);
        $this->assertDatabaseCount('credit_transactions', 0);
        $this->app->instance('env', 'local');
        $this->assertSame(7, $ledger->summary($user)['balance']);
        $ledger->locked($user, function () use ($ledger, $user) {
            $grant = $ledger->grants($user)->first();
            $ledger->entry($user, $grant, -7, 'spent', 'Test item');
        });
        $this->artisan('credits:grant-test-monthly')->assertSuccessful();
        $this->assertSame(0, $ledger->summary($user)['balance']);
        $this->assertDatabaseCount('credit_grants', 1);
    }
}
