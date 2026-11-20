<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\Protocol;
use App\Models\User;
use App\Support\HorseDeletion;
use App\Support\ProtocolAccess;
use App\Support\ProtocolIntakeIntegrity;
use App\Support\ProtocolPurchases;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\HasSubscription;
use Tests\TestCase;

/** Several protocols can use one intake: what follows from that outside the copy action itself. */
class SharedIntakeTest extends TestCase
{
    use HasSubscription, RefreshDatabase;

    private User $customer;

    private Horse $horse;

    private IntakeBooking $intake;

    private Protocol $owner;

    private Horse $testHorse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = User::factory()->create();
        $this->horse = Horse::create(['owner_id' => $this->customer->id, 'name' => 'Bron', 'status' => 'active']);
        $this->intake = IntakeBooking::create(['horse_id' => $this->horse->id, 'user_id' => $this->customer->id, 'submitted_at' => now(), 'intake_status' => 'submitted', 'status' => 'pending']);
        $this->owner = $this->intake->protocol;
        $this->testHorse = Horse::create(['owner_id' => User::factory()->create(['is_test_user' => true])->id, 'name' => 'Test', 'status' => 'active']);
    }

    /** A protocol that uses the customer's intake without owning it, like a copy or an added protocol. */
    private function using(Horse $horse, array $attributes = []): Protocol
    {
        $protocol = new Protocol($attributes + ['horse_id' => $horse->id, 'title' => 'Kopie', 'status' => 'draft']);
        $protocol->forceFill(['intake_booking_id' => $this->intake->id])->save();

        return $protocol;
    }

    public function test_publishing_completes_the_review_only_for_a_protocol_of_the_intakes_own_horse(): void
    {
        $this->using($this->testHorse)->update(['published_at' => now()]);
        $this->assertSame('pending', $this->intake->fresh()->status);

        $this->using($this->horse)->update(['published_at' => now()]);
        $this->assertSame('done', $this->intake->fresh()->status);
    }

    public function test_deleting_the_horse_in_any_way_takes_the_copies_on_other_horses_with_it(): void
    {
        $copy = $this->using($this->testHorse);
        $own = Protocol::create(['horse_id' => $this->testHorse->id, 'title' => 'Eigen', 'status' => 'draft']);
        $preview = app(HorseDeletion::class)->preview($this->horse);
        $this->assertSame([1, 1], [$preview['removed']['protocols']['count'], $preview['removed']['protocol_copies']['count']]);
        $this->assertArrayNotHasKey('protocol_copies', app(HorseDeletion::class)->preview($this->testHorse)['removed']);

        // As the app's sync does it: a plain model delete, without the admin's deletion service.
        $this->horse->delete();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertSame(0, IntakeBooking::where('horse_id', $this->horse->id)->count());
        $this->assertNull($copy->fresh());
        $this->assertNull($this->owner->fresh());
        $this->assertNotNull($own->fresh());
        $this->assertNotNull($this->testHorse->fresh());
    }

    public function test_access_follows_any_unfinished_protocol_of_the_horse_that_uses_the_intake(): void
    {
        $access = app(ProtocolAccess::class);
        $this->assertTrue($access->existingForHorse($this->customer->id, $this->horse->id)->is($this->intake));

        $this->owner->update(['status' => 'completed']);
        $this->using($this->testHorse);
        // Finished on its own horse; a copy on another horse does not keep it open.
        $this->assertNull($access->existingForHorse($this->customer->id, $this->horse->id));
        $this->assertFalse($access->state($this->customer->id, $this->horse->id)['intakeAvailable']);

        // A follow-up on the same intake does, so the app does not offer a purchase next to it.
        $this->using($this->horse);
        $this->assertTrue($access->existingForHorse($this->customer->id, $this->horse->id)->is($this->intake));
        $this->assertTrue($access->state($this->customer->id, $this->horse->id)['intakeAvailable']);
    }

    public function test_a_purchase_reuses_a_follow_up_but_never_a_copy_that_uses_another_horses_intake(): void
    {
        $this->subscribe($this->customer);
        $purchases = app(ProtocolPurchases::class);
        $this->owner->update(['status' => 'completed']);
        $followUp = $this->using($this->horse);
        $this->assertTrue($purchases->createWithoutPayment($this->customer->id, $this->horse->id)->is($followUp));

        // The same user owns a second horse with a copy that uses the first horse's intake.
        $second = Horse::create(['owner_id' => $this->customer->id, 'name' => 'Tweede', 'status' => 'active']);
        $this->using($second);
        try {
            $purchases->createWithoutPayment($this->customer->id, $second->id);
            $this->fail('The purchase returned a protocol whose intake belongs to another horse.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(1, IntakeBooking::count());
    }

    public function test_keeping_the_intake_prefers_a_protocol_in_use_and_archives_the_placeholder_of_a_cancelled_intake(): void
    {
        $archived = $this->using($this->horse, ['status' => 'archived', 'archived_previous_status' => 'paused']);
        $current = $this->using($this->horse);
        DB::table('protocols')->where('id', $archived->id)->update(['created_at' => now()->subDays(2)]);
        DB::table('protocols')->where('id', $current->id)->update(['created_at' => now()->subDay()]);

        $this->assertTrue($this->owner->deleteKeepingIntake()->is($current));
        $this->assertSame($current->id, $this->intake->fresh()->protocol_id);
        // With only finished protocols left, the oldest of them takes it.
        $this->assertTrue($current->fresh()->deleteKeepingIntake()->is($archived));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        // A cancelled intake is history: its placeholder is archived and never looks like an unfinished purchase.
        $this->intake->forceFill(['status' => 'cancelled'])->save();
        $placeholder = $archived->fresh()->deleteKeepingIntake();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertTrue($placeholder->wasRecentlyCreated);
        $this->assertSame(['archived', 'draft', $this->intake->id], [$placeholder->status, $placeholder->archived_previous_status, $placeholder->intake_booking_id]);
        $this->assertSame($placeholder->id, $this->intake->fresh()->protocol_id);
        // Back to checking at commit, as outside this test: a new protocol gets its intake right after its insert.
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->subscribe($this->customer);
        $new = app(ProtocolPurchases::class)->createWithoutPayment($this->customer->id, $this->horse->id);
        $this->assertFalse($new->is($placeholder));
        $this->assertSame(2, IntakeBooking::count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_an_intake_that_other_protocols_use_is_not_deleted_from_its_own_page(): void
    {
        $added = $this->using($this->horse);

        try {
            $this->intake->delete();
            $this->fail('The intake was deleted while another protocol uses it.');
        } catch (ValidationException $exception) {
            $this->assertSame(['booking_delete'], array_keys($exception->errors()));
        }
        $this->assertNotNull($this->intake->fresh());
        $this->assertNotNull($added->fresh());
    }

    public function test_the_database_requires_a_protocol_to_use_the_intake_that_was_created_for_it(): void
    {
        $other = IntakeBooking::create(['horse_id' => $this->horse->id, 'user_id' => $this->customer->id]);
        $this->assertSame([], app(ProtocolIntakeIntegrity::class)->issues());

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($other) {
            DB::table('protocols')->where('id', $this->owner->id)->update(['intake_booking_id' => $other->id]);
            $this->assertSame(['protocols_not_using_their_own_intake' => [$this->owner->id]], app(ProtocolIntakeIntegrity::class)->issues());
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }
}
