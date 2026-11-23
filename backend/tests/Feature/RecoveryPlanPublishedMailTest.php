<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\Protocol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class RecoveryPlanPublishedMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.from.address' => 'sender@example.test', 'app.web_url' => 'https://app.example.test']);
    }

    private function protocol(array $owner = []): Protocol
    {
        $user = User::factory()->create($owner + ['name' => 'Zoë Jansen', 'email' => 'zoe@example.test']);
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Moos', 'status' => 'active']);

        return IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id, 'status' => 'pending', 'submitted_at' => now()])->protocol;
    }

    private function messages()
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages();
    }

    public function test_publishing_mails_the_owner_once_in_plain_text(): void
    {
        $protocol = $this->protocol();
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertNull($protocol->fresh()->published_email_status);
        $this->assertCount(0, $this->messages());

        $protocol->update(['published_at' => now()]);
        $this->assertSame('pending', $protocol->fresh()->published_email_status);
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

    public function test_unpublished_before_sending_waits_and_disabled_owners_are_skipped(): void
    {
        $protocol = $this->protocol();
        $protocol->update(['published_at' => now()]);
        $protocol->fresh()->update(['published_at' => null]);
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertSame('pending', $protocol->fresh()->published_email_status);
        $this->assertCount(0, $this->messages());

        $disabled = $this->protocol(['email' => 'disabled@example.test', 'disabled_at' => now()]);
        $disabled->update(['published_at' => now()]);
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertSame('skipped', $disabled->fresh()->published_email_status);
        $this->assertCount(0, $this->messages());
    }

    public function test_transport_failure_is_recorded_and_not_retried(): void
    {
        $protocol = $this->protocol();
        $protocol->update(['published_at' => now()]);
        Event::listen(MessageSending::class, fn () => throw new RuntimeException('SMTP unavailable'));
        $this->artisan('mail:send-customer-notifications')->assertFailed();
        $this->assertSame('failed', $protocol->fresh()->published_email_status);
        $this->assertSame('SMTP unavailable', $protocol->fresh()->published_email_error);
        Event::forget(MessageSending::class);
        $this->artisan('mail:send-customer-notifications')->assertSuccessful();
        $this->assertCount(0, $this->messages());
    }
}
