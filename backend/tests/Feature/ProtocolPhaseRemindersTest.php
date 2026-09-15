<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\Protocol;
use App\Models\ProtocolTemplate;
use App\Models\User;
use App\Support\ProtocolPhaseReminders;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProtocolPhaseRemindersTest extends TestCase
{
    use RefreshDatabase;

    private function protocol(): Protocol
    {
        $owner = User::factory()->create(['notifications_on' => true]);
        $owner->notificationPreferences()->create(['push_token' => 'ExpoPushToken[test]', 'timezone' => 'Europe/Amsterdam', 'reminder_protocol' => true]);
        $horse = Horse::create(['owner_id' => $owner->id, 'name' => 'Horse', 'status' => 'active']);
        $template = ProtocolTemplate::create(['name' => 'Test protocol']);
        $protocol = $horse->protocols()->create(['protocol_template_id' => $template->id, 'title' => 'Protocol', 'status' => 'active', 'published_at' => '2026-03-01', 'started_at' => '2026-03-22']);
        foreach (['Lever', 'Darmen'] as $index => $name) {
            $definition = $template->phases()->create(['name' => $name, 'order' => $index]);
            $protocol->phases()->create(['protocol_template_phase_id' => $definition->id, 'title' => $name, 'order' => $index, 'week_start' => 3, 'week_end' => 4, 'state' => 'upcoming']);
        }

        return $protocol;
    }

    public function test_parallel_phases_are_grouped_once_and_link_to_the_exact_horse_protocol_and_phases(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-1']])]);
        $protocol = $this->protocol();
        $sender = app(ProtocolPhaseReminders::class);
        $this->assertSame(0, $sender->send($protocol, CarbonImmutable::parse('2026-03-28 23:59:59', 'Europe/Amsterdam')));
        $now = CarbonImmutable::parse('2026-03-29 00:00:00', 'Europe/Amsterdam');
        $this->assertSame(1, $sender->send($protocol, $now));
        $this->assertSame(0, $sender->send($protocol->fresh(), $now->addMinute()));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['title'] === 'Je volgende fase staat klaar 🌿'
            && $request['body'] === 'Over een week starten Lever en Darmen. Bekijk alvast wat er verandert en welke kruiden of supplementen je nodig hebt.'
            && $request['data']['horseId'] === $protocol->horse_id
            && $request['data']['protocolId'] === $protocol->id
            && count($request['data']['phaseIds']) === 2);
        $this->assertDatabaseCount('protocol_phase_reminders', 2);
    }

    public function test_failed_transport_retries_without_recording_false_success_and_opt_out_is_respected(): void
    {
        $protocol = $this->protocol();
        $now = CarbonImmutable::parse('2026-03-29', 'Europe/Amsterdam');
        Http::fake(['exp.host/*' => Http::sequence()->push([], 503)->push(['data' => ['status' => 'ok', 'id' => 'retry']])]);
        try {
            app(ProtocolPhaseReminders::class)->send($protocol, $now);
            $this->fail('Expected transport failure');
        } catch (RequestException) {
            $this->assertDatabaseCount('protocol_phase_reminders', 0);
        }
        $this->assertSame(1, app(ProtocolPhaseReminders::class)->send($protocol, $now));
        $protocol->horse->owner->notificationPreferences->update(['reminder_protocol' => false]);
        DB::table('protocol_phase_reminders')->delete();
        $this->assertSame(0, app(ProtocolPhaseReminders::class)->send($protocol->fresh(), $now));
        Http::assertSentCount(2);
    }

    public function test_invalid_device_token_is_removed_and_unpublished_protocol_is_skipped(): void
    {
        $protocol = $this->protocol();
        Http::fake(['exp.host/*' => Http::response(['data' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]])]);
        $now = CarbonImmutable::parse('2026-03-29', 'Europe/Amsterdam');
        $this->assertSame(0, app(ProtocolPhaseReminders::class)->send($protocol, $now));
        $this->assertNull($protocol->horse->owner->notificationPreferences->fresh()->push_token);
        $protocol->update(['published_at' => null]);
        $this->assertSame(0, app(ProtocolPhaseReminders::class)->send($protocol->fresh(), $now));
        Http::assertSentCount(1);
    }

    public function test_retry_does_not_repeat_an_earlier_committed_availability_group(): void
    {
        $protocol = $this->protocol();
        // An edited schedule can leave two different availability dates within a preview window.
        // Use successive send() invocations to emulate a previous group accepted before a failure.
        $now = CarbonImmutable::parse('2026-03-29', 'Europe/Amsterdam');
        Http::fake(['exp.host/*' => Http::sequence()
            ->push(['data' => ['status' => 'ok', 'id' => 'first']])
            ->push([], 503)
            ->push(['data' => ['status' => 'ok', 'id' => 'second']])]);
        $sender = app(ProtocolPhaseReminders::class);
        $sender->send($protocol, $now);
        $definition = $protocol->protocolTemplate->phases()->create(['name' => 'Extra', 'order' => 2]);
        $extra = $protocol->phases()->create(['protocol_template_phase_id' => $definition->id, 'title' => 'Extra', 'order' => 2, 'week_start' => 3, 'week_end' => 4, 'state' => 'upcoming']);
        try {
            $sender->send($protocol->fresh(), $now);
            $this->fail('Expected failure');
        } catch (RequestException) {
            $this->assertDatabaseCount('protocol_phase_reminders', 2);
        }
        $sender->send($protocol->fresh(), $now);
        $this->assertDatabaseCount('protocol_phase_reminders', 3);
        $requests = Http::recorded();
        $this->assertSame([$extra->id], $requests[2][0]['data']['phaseIds']);
    }
}
