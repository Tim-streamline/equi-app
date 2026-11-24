<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\Protocol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtocolOrderChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->actAs($this->owner);
    }

    private function actAs(User $user): void
    {
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($user->id)
        {
            public function __construct(private string $userId) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->userId);

                return $next($request);
            }
        });
    }

    private function protocol(bool $published = true): Protocol
    {
        $horse = Horse::create(['owner_id' => $this->owner->id, 'name' => 'Moos', 'status' => 'active']);
        $protocol = IntakeBooking::create(['user_id' => $this->owner->id, 'horse_id' => $horse->id, 'status' => 'pending', 'submitted_at' => now()])->protocol;
        if ($published) {
            $protocol->update(['status' => 'active', 'published_at' => now()->subMinute()]);
        }

        return $protocol->fresh();
    }

    private function tick(Protocol $protocol, array $keys, bool $ordered)
    {
        return $this->postJson('/api/horses/'.$protocol->horse_id.'/protocol-orders', ['protocol_id' => $protocol->id, 'keys' => $keys, 'ordered' => $ordered]);
    }

    public function test_products_are_ticked_and_unticked_per_phase_without_touching_the_protocol(): void
    {
        $protocol = $this->protocol();
        $updatedAt = $protocol->updated_at;
        $this->travel(5)->minutes();

        $this->tick($protocol, ['product:f1', 'product:f2'], true)->assertOk()->assertExactJson(['orderedKeys' => ['product:f1', 'product:f2']]);
        $this->tick($protocol, ['other:f1', 'product:f1'], true)->assertOk()->assertJsonPath('orderedKeys', ['product:f1', 'product:f2', 'other:f1']);
        $this->tick($protocol, ['product:f1', 'product:f2'], false)->assertOk()->assertJsonPath('orderedKeys', ['other:f1']);

        $this->assertSame(['other:f1'], $protocol->fresh()->ordered_product_keys);
        $this->assertEquals($updatedAt, $protocol->fresh()->updated_at);
    }

    public function test_only_the_owner_can_tick_and_only_on_a_published_plan(): void
    {
        $protocol = $this->protocol();
        $this->tick($protocol, ['bad key'], true)->assertUnprocessable();
        $this->tick($protocol, [], true)->assertUnprocessable();

        $draft = $this->protocol(published: false);
        $this->tick($draft, ['product:f1'], true)->assertNotFound();

        $this->actAs(User::factory()->create());
        $this->tick($protocol, ['product:f1'], true)->assertNotFound();
        $this->assertNull($protocol->fresh()->ordered_product_keys);
    }

    public function test_writes_follow_what_the_owner_can_see(): void
    {
        $protocol = $this->protocol();
        // A fresh draft, even with a publication date, and a plan published in the future are not visible yet.
        Protocol::whereKey($protocol->id)->toBase()->update(['status' => 'draft']);
        $this->tick($protocol, ['product:f1'], true)->assertNotFound();
        Protocol::whereKey($protocol->id)->toBase()->update(['status' => 'active', 'published_at' => now()->addDay()]);
        $this->tick($protocol, ['product:f1'], true)->assertNotFound();
        Protocol::whereKey($protocol->id)->toBase()->update(['status' => 'archived', 'published_at' => now()->subDay()]);
        $this->tick($protocol, ['product:f1'], true)->assertNotFound();
        $this->assertNull($protocol->fresh()->ordered_product_keys);

        // Paused and completed plans stay readable, and so does their order list.
        foreach (['paused', 'completed'] as $status) {
            Protocol::whereKey($protocol->id)->toBase()->update(['status' => $status]);
            $this->tick($protocol, [$status.':f1'], true)->assertOk();
        }
        $this->assertSame(['paused:f1', 'completed:f1'], $protocol->fresh()->ordered_product_keys);

        // Ownership still applies to visible plans.
        $this->actAs(User::factory()->create());
        $this->tick($protocol, ['other:f1'], true)->assertNotFound();
    }
}
