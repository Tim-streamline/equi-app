<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\Protocol;
use App\Models\User;
use App\Mail\RecoveryPlanPublished;
use App\Support\CustomerNotificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\HasSubscription;
use Tests\TestCase;

class RecoveryPlanPublishedMailTest extends TestCase
{
    use HasSubscription, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.from.address' => 'sender@example.test', 'app.web_url' => 'https://app.example.test']);
    }

    private function protocol(array $owner = []): Protocol
    {
        $user = User::factory()->create($owner + ['name' => 'Zoë Jansen', 'email' => 'zoe@example.test']);
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Moos', 'status' => 'active']);

        $protocol = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id, 'status' => 'pending', 'submitted_at' => now()])->protocol;
        // An intake starts its protocol as a draft; the customer only sees it once the therapist has made it active.
        $protocol->update(['status' => 'active']);

        return $protocol->fresh();
    }

    private function messages()
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages();
    }

    public function test_publishing_mails_the_owner_once_in_plain_text(): void
    {
        $protocol = $this->protocol();
        $this->subscribe($protocol->horse->owner);
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertNull($protocol->fresh()->published_email_status);
        $this->assertCount(0, $this->messages());

        $protocol->update(['published_at' => now()]);
        $this->assertSame('pending', $protocol->fresh()->published_email_status);
        // The mail waits half an hour, so a correction made straight after publishing is not announced twice.
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->travel(29)->minutes();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertCount(0, $this->messages());
        $this->assertSame('pending', $protocol->fresh()->published_email_status);
        $this->travel(2)->minutes();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();

        $this->assertCount(1, $this->messages());
        $message = $this->messages()->first()->getOriginalMessage();
        $this->assertSame('zoe@example.test', $message->getTo()[0]->getAddress());
        $this->assertSame('Het herstelplan voor Moos staat klaar | EquiApp', $message->getSubject());
        foreach (['Hey Zoë,', 'Het herstelplan voor Moos staat klaar in EquiApp!', "👉 Bekijk het herstelplan van Moos:\nhttps://app.example.test/\n", "Liefs,\nShelley\nDe Paardentherapeut | EquiApp"] as $text) {
            $this->assertStringContainsString($text, $message->getTextBody());
        }
        $this->assertNull($message->getHtmlBody());
        $this->assertSame('sent', $protocol->fresh()->published_email_status);

        // Later edits, unpublishing and republishing never mail again.
        $protocol->fresh()->update(['title' => 'Nieuwe titel']);
        $protocol->fresh()->update(['published_at' => null]);
        $protocol->fresh()->update(['published_at' => now()]);
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertCount(1, $this->messages());
    }

    public function test_expiry_before_delivery_changes_the_call_to_action_and_keeps_the_paid_plan_notice(): void
    {
        $protocol = $this->protocol();
        $protocol->horse->update(['name' => "D'Artagnan & Co"]);
        $this->subscribe($protocol->horse->owner)->update(['paid_through' => now()->addMinutes(15)]);
        $protocol->update(['published_at' => now()]);
        $this->travel(31)->minutes();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertCount(1, $this->messages());
        $body = $this->messages()->first()->getOriginalMessage()->getTextBody();
        $this->assertStringContainsString("Verleng je abonnement in de app om het herstelplan van D'Artagnan & Co te bekijken:", $body);
        $this->assertStringNotContainsString('👉 Bekijk', $body);
        $this->assertSame('sent', $protocol->fresh()->published_email_status);
    }

    public function test_superseded_announcement_is_skipped_permanently(): void
    {
        $first = $this->protocol();
        $first->update(['published_at' => now()]);
        $this->travel(10)->minutes();
        $first->update(['status' => 'paused']);
        $next = $first->horse->protocols()->create(['title' => 'Vervolg', 'status' => 'active', 'published_at' => now()]);
        $this->travel(31)->minutes();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertSame('skipped', $first->fresh()->published_email_status);
        $this->assertSame('sent', $next->fresh()->published_email_status);
        $next->update(['status' => 'completed']);
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertCount(1, $this->messages());
    }

    public function test_a_paused_follow_up_is_announced_when_it_becomes_the_shown_plan(): void
    {
        $first = $this->protocol();
        $first->update(['published_at' => now()]);
        $this->travel(10)->minutes();
        $next = $first->horse->protocols()->create(['title' => 'Vervolg', 'status' => 'paused', 'published_at' => now()]);
        $this->travel(31)->minutes();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertSame('sent', $first->fresh()->published_email_status);
        $this->assertSame('pending', $next->fresh()->published_email_status);
        $this->assertNull($next->fresh()->published_email_claimed_at);
        $this->assertCount(1, $this->messages());

        $first->update(['status' => 'completed']);
        $next->update(['status' => 'active']);
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertSame('sent', $next->fresh()->published_email_status);
        $this->assertCount(2, $this->messages());
    }

    public function test_removing_a_mistaken_publication_before_delivery_preserves_the_original_notice(): void
    {
        $first = $this->protocol();
        $first->update(['published_at' => now()]);
        $this->travel(10)->minutes();
        $first->update(['status' => 'paused']);
        $mistake = $first->horse->protocols()->create(['title' => 'Correctie', 'status' => 'active', 'published_at' => now()]);
        $this->travel(21)->minutes();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertCount(0, $this->messages());
        $this->assertNull($first->fresh()->published_email_claimed_at);

        $mistake->update(['published_at' => null]);
        $this->travel(10)->minutes();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertSame('sent', $first->fresh()->published_email_status);
        $this->assertNull($mistake->fresh()->published_email_claimed_at);
        $this->assertCount(1, $this->messages());
    }

    public function test_unpublished_before_sending_waits_and_disabled_owners_are_skipped(): void
    {
        $protocol = $this->protocol();
        $protocol->update(['published_at' => now()]);
        $protocol->fresh()->update(['published_at' => null]);
        $this->travel(40)->minutes();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertSame('pending', $protocol->fresh()->published_email_status);
        $this->assertCount(0, $this->messages());

        // Publishing again restarts the half hour.
        $protocol->fresh()->update(['published_at' => now()]);
        $this->travel(29)->minutes();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertCount(0, $this->messages());
        $this->travel(2)->minutes();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertCount(1, $this->messages());

        $disabled = $this->protocol(['email' => 'disabled@example.test', 'disabled_at' => now()]);
        $disabled->update(['published_at' => now()]);
        $this->travel(31)->minutes();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertSame('skipped', $disabled->fresh()->published_email_status);
        $this->assertCount(1, $this->messages(), 'only the republished plan above was mailed');
    }

    public function test_a_published_draft_is_not_announced_until_the_customer_can_open_it(): void
    {
        $protocol = $this->protocol();
        $protocol->update(['status' => 'draft', 'published_at' => now()]);
        $this->travel(40)->minutes();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertCount(0, $this->messages());

        $protocol->fresh()->update(['status' => 'active']);
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertCount(1, $this->messages());
    }

    public function test_a_plan_archived_within_the_half_hour_is_not_announced(): void
    {
        $protocol = $this->protocol();
        $protocol->update(['published_at' => now()]);
        $protocol->fresh()->update(['status' => 'archived', 'archived_previous_status' => 'active']);
        $this->travel(40)->minutes();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertCount(0, $this->messages());
        $this->assertSame('pending', $protocol->fresh()->published_email_status);

        // Restored and visible again: the customer hears about it then.
        $protocol->fresh()->update(['status' => 'active', 'archived_previous_status' => null]);
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertCount(1, $this->messages());
    }

    public function test_a_plan_of_an_archived_horse_is_not_announced_until_the_horse_is_restored(): void
    {
        $protocol = $this->protocol();
        $protocol->update(['published_at' => now()]);
        $protocol->horse->update(['status' => 'archived']);
        $this->travel(40)->minutes();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        // The claim itself re-checks eligibility, so a direct send between scan and claim also waits.
        app(CustomerNotificationMail::class)->sendRecoveryPlanPublished($protocol->id);
        $this->assertCount(0, $this->messages());
        $this->assertSame('pending', $protocol->fresh()->published_email_status);
        $this->assertNull($protocol->fresh()->published_email_claimed_at);

        // Restored soon after: no expiry, the customer hears about the plan then.
        $protocol->horse->update(['status' => 'active']);
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertCount(1, $this->messages());
        $this->assertSame('sent', $protocol->fresh()->published_email_status);
    }

    public function test_mailers_that_do_not_deliver_hold_mail_before_claiming(): void
    {
        $protocol = $this->protocol();
        $protocol->update(['published_at' => now()]);
        $this->travel(31)->minutes();
        $this->app['env'] = 'production';
        try {
            config(['mail.mailers.logging-failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']],
                'mail.mailers.array-roundrobin' => ['transport' => 'roundrobin', 'mailers' => ['smtp', 'logging-failover']]]);
            foreach (['log', 'array', 'logging-failover', 'array-roundrobin', 'missing'] as $mailer) {
                config(['mail.default' => $mailer]);
                $this->assertNotNull(app(CustomerNotificationMail::class)->deliveryHold(), $mailer);
                $this->artisan('mail:send-customer-notifications')->assertFailed();
                app(CustomerNotificationMail::class)->sendRecoveryPlanPublished($protocol->id);
                $this->assertSame('pending', $protocol->fresh()->published_email_status, $mailer);
                $this->assertNull($protocol->fresh()->published_email_claimed_at, $mailer);
            }
            // Real transports, alone or combined, are not held.
            config(['mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.real-failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'sendmail']]]);
            foreach (['smtp', 'sendmail', 'real-failover', 'postmark'] as $mailer) {
                config(['mail.default' => $mailer]);
                $this->assertNull(app(CustomerNotificationMail::class)->deliveryHold(), $mailer);
            }
            // A deliberate Mail::fake() is a test fake, whatever the configured mailer.
            config(['mail.default' => 'log']);
            Mail::fake();
            $this->artisan('mail:send-customer-notifications')->assertSuccessful();
            Mail::assertSent(RecoveryPlanPublished::class, 1);
            $this->assertSame('sent', $protocol->fresh()->published_email_status);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_transport_failure_is_recorded_and_not_retried(): void
    {
        $protocol = $this->protocol();
        $protocol->update(['published_at' => now()]);
        $this->travel(31)->minutes();
        Event::listen(MessageSending::class, fn () => throw new RuntimeException('SMTP unavailable'));
        $this->artisan('mail:send-customer-notifications')->assertFailed();
        $this->assertSame('failed', $protocol->fresh()->published_email_status);
        $this->assertSame('SMTP unavailable', $protocol->fresh()->published_email_error);
        Event::forget(MessageSending::class);
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertCount(0, $this->messages());
    }
}
