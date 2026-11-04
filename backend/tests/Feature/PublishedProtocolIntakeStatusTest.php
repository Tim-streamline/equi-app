<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublishedProtocolIntakeStatusTest extends TestCase
{
    use RefreshDatabase;

    private function booking(string $status = 'pending', bool $submitted = true): IntakeBooking
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Moos', 'status' => 'active']);

        return IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id,
            'status' => $status, 'submitted_at' => $submitted ? now() : null]);
    }

    public function test_publication_completes_review_and_updates_the_admin_count_and_filter(): void
    {
        $booking = $this->booking();
        $pending = $this->booking();
        $booking->protocol->update(['published_at' => now()]);
        $this->assertSame('done', $booking->fresh()->status);
        $this->assertSame('Beoordeeld', $booking->fresh()->status_label);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->actingAs(AdminUser::create(['name' => 'Admin', 'email' => 'published@example.test',
            'password' => 'password', 'role' => 'owner', 'active' => true]), 'admin');
        $this->get('/admin/bookings?status=pending')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('counts.pending', 1)
                ->has('bookings.data', 1)->where('bookings.data.0.id', $pending->id));
        $this->get('/admin/bookings?status=done')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('bookings.data', 1)
                ->where('bookings.data.0.id', $booking->id)->where('bookings.data.0.status_label', 'Beoordeeld'));
    }

    public function test_publication_preserves_cancelled_and_unsubmitted_intakes_and_unpublishing_does_not_reopen_review(): void
    {
        foreach ([['cancelled', true], ['pending', false]] as [$status, $submitted]) {
            $booking = $this->booking($status, $submitted);
            $booking->protocol->update(['published_at' => now()]);
            $this->assertSame($status, $booking->fresh()->status);
        }
        $booking = $this->booking('confirmed');
        $booking->protocol->update(['published_at' => now()]);
        $this->assertSame('done', $booking->fresh()->status);
        $booking->protocol->update(['published_at' => null]);
        $this->assertSame('done', $booking->fresh()->status);
    }

    public function test_later_saves_of_a_published_protocol_keep_a_reopened_review_until_republication(): void
    {
        $booking = $this->booking();
        $protocol = $booking->protocol;
        $protocol->update(['published_at' => now()->subMinute(), 'status' => 'active']);
        $this->assertSame('done', $booking->fresh()->status);
        $booking->update(['status' => 'confirmed']);

        $protocol->fresh()->update(['title' => 'Nieuwe titel']);
        $this->assertSame('confirmed', $booking->fresh()->status);
        // Changing an existing publication timestamp is not a new publication.
        $protocol->fresh()->update(['published_at' => now()]);
        $this->assertSame('confirmed', $booking->fresh()->status);
        $ownerId = $booking->user_id;
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($ownerId)
        {
            public function __construct(private string $userId) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->userId);

                return $next($request);
            }
        });
        $this->postJson('/api/horses/'.$booking->horse_id.'/protocol-start', [
            'protocol_id' => $protocol->id, 'confirmed' => true,
        ])->assertOk();
        $this->assertNotNull($protocol->fresh()->started_at);
        $this->assertSame('confirmed', $booking->fresh()->status);

        // Unpublishing keeps the review; publishing again completes it like the first publication.
        $protocol->fresh()->update(['published_at' => null]);
        $this->assertSame('confirmed', $booking->fresh()->status);
        $protocol->fresh()->update(['published_at' => now()]);
        $this->assertSame('done', $booking->fresh()->status);
    }

    public function test_upgrade_repairs_existing_published_protocols_only_and_is_repeatable(): void
    {
        $published = $this->booking();
        $cancelled = $this->booking('cancelled');
        $unsubmitted = $this->booking('pending', false);
        $draft = $this->booking();
        foreach ([$published, $cancelled, $unsubmitted] as $booking) {
            // Simulate records saved before publication updated the intake review state.
            DB::table('protocols')->where('id', $booking->protocol_id)->update(['published_at' => now()]);
        }
        $migration = require database_path('migrations/2026_11_01_000001_complete_published_protocol_intake_reviews.php');
        $migration->up();
        $migration->up();
        $this->assertSame('done', $published->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertSame('pending', $unsubmitted->fresh()->status);
        $this->assertSame('pending', $draft->fresh()->status);
    }
}
