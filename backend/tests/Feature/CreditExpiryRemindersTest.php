<?php

namespace Tests\Feature;

use App\Models\{LibraryItem, NotificationPreference, User};
use App\Support\{CreditExpiryReminders, CreditLedger};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class CreditExpiryRemindersTest extends TestCase
{
    use RefreshDatabase;
    private User $user;
    private NotificationPreference $preferences;
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-27 12:00:00'));
        $this->user = User::factory()->create(['notifications_on' => true]);
        $this->preferences = NotificationPreference::create(['user_id' => $this->user->id, 'push_token' => 'ExponentPushToken[test-only]', 'timezone' => 'Europe/Amsterdam']);
        Http::preventStrayRequests();
    }
    private function grant(int $days, int $amount = 3): string
    {
        return app(CreditLedger::class)->grant($this->user, $amount, 'purchased', now()->addDays($days)->toDateTimeString());
    }
    public function test_scheduled_command_sends_thirty_and_seven_day_reminders_once_with_current_remaining_amount(): void
    {
        $grant = $this->grant(31); Http::fake(['exp.host/*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket']])]);
        $this->artisan('credits:send-expiry-reminders')->assertSuccessful(); Http::assertNothingSent();
        $this->travel(1)->days();
        $this->artisan('credits:send-expiry-reminders')->assertSuccessful();
        $this->artisan('credits:send-expiry-reminders')->assertSuccessful(); Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['body'] === '3 credits verlopen op 28 oktober.' && $r['data']['userId'] === $this->user->id && $r['data']['type'] === 'credit_expiry');
        $item = LibraryItem::create(['title' => 'Worksheet', 'slug' => 'worksheet', 'format' => 'article']);
        $ledger = app(CreditLedger::class); $ledger->locked($this->user, fn () => $ledger->spend($this->user, 2, $item->id));
        $this->travel(23)->days();
        // Exercise the sender independently of old receipt polling after the time jump.
        $this->assertSame(1, app(CreditExpiryReminders::class)->send($this->user));
        $this->assertSame(0, app(CreditExpiryReminders::class)->send($this->user));
        Http::assertSentCount(2); Http::assertSent(fn ($r) => $r['body'] === '1 credit verloopt op 28 oktober.');
        $this->assertDatabaseHas('credit_reminders', ['grant_id' => $grant, 'days' => 30]);
        $this->assertDatabaseHas('credit_reminders', ['grant_id' => $grant, 'days' => 7]);
    }
    public function test_spent_expired_opted_out_disabled_and_missing_device_accounts_are_skipped(): void
    {
        Http::fake(); $grant = $this->grant(5);
        $this->user->update(['notifications_on' => false]); $this->artisan('credits:send-expiry-reminders')->assertSuccessful();
        $this->user->update(['notifications_on' => true, 'disabled_at' => now()]); $this->artisan('credits:send-expiry-reminders')->assertSuccessful();
        $this->user->update(['disabled_at' => null]); $this->preferences->update(['push_token' => null]); $this->artisan('credits:send-expiry-reminders')->assertSuccessful();
        $this->preferences->update(['push_token' => 'ExponentPushToken[test-only]']);
        $ledger = app(CreditLedger::class); $g = $ledger->grants($this->user)->firstWhere('id', $grant);
        $ledger->entry($this->user, $g, -3, 'spent', 'Spent');
        $this->grant(-1); $this->artisan('credits:send-expiry-reminders')->assertSuccessful();
        Http::assertNothingSent(); $this->assertDatabaseCount('credit_reminders', 0);
    }
    public function test_rejected_delivery_retries_and_late_start_sends_only_seven_day_notice(): void
    {
        $grant = $this->grant(5);
        Http::fake(['exp.host/*' => Http::sequence()->push(['error' => 'unavailable'], 503)->push(['data' => ['status' => 'ok', 'id' => 'retry-ticket']])]);
        $this->artisan('credits:send-expiry-reminders')->assertFailed(); $this->assertDatabaseCount('credit_reminders', 0);
        $this->artisan('credits:send-expiry-reminders')->assertSuccessful();
        $this->artisan('credits:send-expiry-reminders')->assertSuccessful(); Http::assertSentCount(2);
        $this->assertDatabaseHas('credit_reminders', ['grant_id' => $grant, 'days' => 7]); $this->assertDatabaseCount('credit_reminders', 1);
    }
    public function test_unregistered_ticket_clears_device_and_does_not_mark_sent(): void
    {
        $this->grant(5); Http::fake(['exp.host/*' => Http::response(['data' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]])]);
        $this->artisan('credits:send-expiry-reminders')->assertSuccessful();
        $this->assertNull($this->preferences->fresh()->push_token); $this->assertDatabaseCount('credit_reminders', 0);
    }
    public function test_receipts_checked_after_fifteen_minutes_without_clearing_a_replaced_device_token(): void
    {
        $grant = $this->grant(5);
        Http::fake(['exp.host/*/send' => Http::response(['data' => ['status' => 'ok', 'id' => 'accepted']]),
            'exp.host/*/getReceipts' => Http::response(['data' => ['accepted' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]])]);
        $this->artisan('credits:send-expiry-reminders')->assertSuccessful();
        $this->preferences->update(['push_token' => 'ExponentPushToken[replacement]']);
        $this->travel(16)->minutes(); $this->artisan('credits:send-expiry-reminders')->assertSuccessful();
        $this->artisan('credits:send-expiry-reminders')->assertSuccessful(); Http::assertSentCount(2);
        $this->assertSame('ExponentPushToken[replacement]', $this->preferences->fresh()->push_token);
        $this->assertDatabaseHas('credit_reminders', ['grant_id' => $grant, 'days' => 7, 'receipt_error' => 'DeviceNotRegistered', 'push_token' => null]);
    }
    public function test_receipt_device_error_clears_matching_token_and_success_records_no_error(): void
    {
        $grant = $this->grant(5);
        DB::table('credit_reminders')->insert(['grant_id' => $grant, 'days' => 7, 'sent_at' => now()->subMinutes(20), 'ticket_id' => 'bad', 'push_token' => $this->preferences->push_token]);
        Http::fake(['exp.host/*' => Http::response(['data' => ['bad' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']], 'good' => ['status' => 'ok']]])]);
        app(CreditExpiryReminders::class)->receipts(); $this->assertNull($this->preferences->fresh()->push_token);
        $other = $this->grant(25);
        DB::table('credit_reminders')->insert(['grant_id' => $other, 'days' => 30, 'sent_at' => now()->subMinutes(20), 'ticket_id' => 'good']);
        app(CreditExpiryReminders::class)->receipts();
        $this->assertNotNull(DB::table('credit_reminders')->where('grant_id', $other)->value('receipt_checked_at'));
        $this->assertDatabaseHas('credit_reminders', ['grant_id' => $other, 'receipt_error' => null]);
    }
}
