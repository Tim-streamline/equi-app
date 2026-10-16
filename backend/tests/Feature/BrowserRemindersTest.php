<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\ProtocolTemplate;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Support\BrowserPush;
use App\Support\BrowserReminders;
use App\Support\CreditLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrowserRemindersTest extends TestCase
{
    use RefreshDatabase;

    private array $messages = [];

    private function user(): User
    {
        $user = User::factory()->create(['notifications_on' => true]);
        $user->notificationPreferences()->create(['reminder_protocol' => true, 'timezone' => 'Europe/Amsterdam']);
        $this->mock(BrowserPush::class, function ($mock) {
            $mock->shouldReceive('destinations')->andReturn(['browser' => new WebPushSubscription]);
            $mock->shouldReceive('send')->andReturnUsing(function ($user, $subscription, $payload) {
                $this->messages[] = $payload;

                return true;
            });
        });

        return $user;
    }

    public function test_browser_only_protocol_groups_deduplicate_per_phase_and_obey_preferences(): void
    {
        $user = $this->user();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $template = ProtocolTemplate::create(['name' => 'Test']);
        $protocol = $horse->protocols()->create(['protocol_template_id' => $template->id, 'title' => 'Protocol', 'status' => 'active', 'published_at' => '2026-03-01', 'started_at' => '2026-03-22']);
        $addPhase = function ($name) use ($template, $protocol) {
            $definition = $template->phases()->create(['name' => $name, 'order' => 1]);

            return $protocol->phases()->create(['protocol_template_phase_id' => $definition->id, 'title' => $name, 'order' => 1, 'week_start' => 3, 'week_end' => 4, 'state' => 'upcoming']);
        };
        $first = $addPhase('Lever');
        $sender = app(BrowserReminders::class);
        $now = CarbonImmutable::parse('2026-03-29', 'Europe/Amsterdam');
        $this->assertSame(0, $sender->protocol($protocol, $now->subSecond()));
        $this->assertSame(1, $sender->protocol($protocol, $now));
        $this->assertSame(0, $sender->protocol($protocol, $now));
        $second = $addPhase('Darmen');
        $this->assertSame(1, $sender->protocol($protocol, $now));
        $this->assertSame([$second->id], $this->messages[1]['data']['phaseIds']);
        $user->notificationPreferences()->update(['reminder_protocol' => false]);
        $addPhase('Extra');
        $this->assertSame(0, $sender->protocol($protocol, $now));
        $this->assertDatabaseCount('browser_reminder_deliveries', 2);
        $this->assertDatabaseCount('protocol_phase_reminders', 0);
    }

    public function test_browser_only_credit_reminders_obey_30_and_7_day_thresholds_without_native_receipts(): void
    {
        $this->freezeTime();
        $user = $this->user();
        app(CreditLedger::class)->grant($user, 3, 'purchased', now()->addDays(30)->toDateTimeString());
        $sender = app(BrowserReminders::class);
        $this->assertSame(1, $sender->credits($user));
        $this->assertSame(0, $sender->credits($user));
        $this->assertSame('credit_expiry', $this->messages[0]['data']['type']);
        $this->travel(23)->days();
        $this->assertSame(1, $sender->credits($user));
        $this->assertSame(0, $sender->credits($user));
        $this->assertDatabaseCount('browser_reminder_deliveries', 2);
        $this->assertDatabaseCount('credit_reminders', 0);
        $user->update(['notifications_on' => false]);
        app(CreditLedger::class)->grant($user, 2, 'purchased', now()->addDays(5)->toDateTimeString());
        $this->assertSame(0, $sender->credits($user));
    }
}
