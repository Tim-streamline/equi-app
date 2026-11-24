<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\Protocol;
use App\Models\ProtocolTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** An early completion ends the plan on its last day: later days are never missed, shown or counted. */
class ProtocolCompletionCutoffTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Horse $horse;

    private Protocol $protocol;

    protected function setUp(): void
    {
        parent::setUp();
        // Saturday 22 August, 23:30 in Amsterdam (21:30 UTC): the owner's local date is still the 22nd.
        $this->travelTo(now()->setDate(2026, 8, 22)->setTime(21, 30));
        $this->owner = User::factory()->create();
        $this->owner->notificationPreferences()->create(['timezone' => 'Europe/Amsterdam']);
        $this->horse = Horse::create(['owner_id' => $this->owner->id, 'name' => 'Moos', 'status' => 'active']);
        $template = ProtocolTemplate::create(['name' => 'Test protocol']);
        $this->protocol = $this->horse->protocols()->create([
            'protocol_template_id' => $template->id, 'title' => 'Plan', 'status' => 'active',
            'published_at' => now()->subWeek(), 'started_at' => '2026-08-15', 'total_weeks' => 4, 'current_week' => 2,
        ]);
        $definition = $template->phases()->create(['name' => 'Phase', 'order' => 0]);
        $phase = $this->protocol->phases()->create(['protocol_template_phase_id' => $definition->id, 'title' => 'Phase', 'state' => 'active', 'order' => 0, 'week_start' => 1, 'week_end' => 4]);
        $supplement = $phase->supplements()->create(['name' => 'Herb', 'dosage' => '20 g']);
        foreach (range(1, 4) as $number) {
            $supplement->weeks()->create(['protocol_phase_week_id' => $phase->weeks()->create(['number' => $number, 'protocol_week_number' => $number])->id]);
        }
        $ownerId = $this->owner->id;
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($ownerId)
        {
            public function __construct(private string $userId) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->userId);
                $request->attributes->set('powersync_claims', ['protocol_doses' => 1, 'read_only_plans' => 1, 'read_only_doses' => 1]);

                return $next($request);
            }
        });
    }

    private function given(string $date, int $administration = 1): void
    {
        DB::table('protocol_supplement_intakes')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'horse_id' => $this->horse->id,
            'protocol_phase_supplement_id' => $this->protocol->phases()->first()->supplements()->first()->id,
            'date' => $date, 'administration' => $administration, 'dosage' => '20 g', 'done' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function dashboard(): array
    {
        return $this->getJson('/api/horses/'.$this->horse->id.'/dashboard?month=2026-08&timezone=Europe/Amsterdam')->assertOk()->json('protocol');
    }

    private function cell(array $protocol, string $date): array
    {
        return collect($protocol['calendar']['cells'])->filter()->firstWhere('date', $date);
    }

    private function dayUrl(string $date): string
    {
        return '/api/horses/'.$this->horse->id.'/protocol-day?'.http_build_query(['protocol_id' => $this->protocol->id, 'date' => $date, 'timezone' => 'Europe/Amsterdam']);
    }

    public function test_ordered_branch_upgrade_keeps_the_evening_dose_on_a_completed_plan(): void
    {
        $item = $this->protocol->phases()->first()->supplements()->first();
        $item->update(['administrations_per_day' => 2]);
        $this->given('2026-08-20', 2);
        $this->protocol->update(['status' => 'completed']);
        DB::table('audit_logs')->insert(['action' => 'updated', 'target_type' => 'Protocol', 'target_id' => $this->protocol->id,
            'before' => '{"status":"active"}', 'after' => '{"status":"completed"}', 'created_at' => '2026-08-20 14:00:00']);

        // Restore the branch-point schema around this existing plan and run every release migration in order.
        $files = array_filter(glob(database_path('migrations/2026_11_*.php')), static fn ($file) =>
            basename($file) >= '2026_11_18' && ! str_starts_with(basename($file), '2026_11_20_')
            && basename($file) <= '2026_11_23_000004_add_revision_to_protocol_evaluations.php');
        foreach (array_reverse($files) as $file) {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            (require $file)->down();
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
        foreach ($files as $file) {
            (require $file)->up();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
        $this->assertSame(1, $item->fresh()->administrationsPerDay());
        $this->assertDatabaseHas('protocol_supplement_intakes', ['protocol_phase_supplement_id' => $item->id, 'administration' => 2, 'done' => true]);
        $this->getJson($this->dayUrl('2026-08-20'))->assertOk()->assertJsonCount(2, 'items')
            ->assertJsonPath('items.1.administration', 2)->assertJsonPath('items.1.done', true)->assertJsonPath('state', 'partial');
        $this->assertSame('partial', $this->cell($this->dashboard(), '2026-08-20')['state']);
    }

    public function test_frequency_change_then_completion_preserves_todays_tick_and_tomorrows_history(): void
    {
        $item = $this->protocol->phases()->first()->supplements()->first();
        $catalog = $item->phase->phase->supplements()->create(['name' => 'Herb', 'supplement_type' => 'kruid', 'administrations_per_day' => 2]);
        $item->update(['supplement_id' => $catalog->id]);
        $this->given('2026-08-22', 2);
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'doses@example.test', 'password' => 'password', 'role' => 'owner', 'active' => true]);
        $this->actingAs($admin, 'admin')->put('/admin/protocol-settings/supplements/'.$catalog->id, [
            'protocol_template_phase_id' => $catalog->protocol_template_phase_id, 'name' => 'Herb', 'supplement_type' => 'kruid',
            'administrations_per_day' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, $item->fresh()->administrationsPerDay());
        $this->assertTrue($this->dashboard()['today']['items'][1]['done']);
        $this->protocol->update(['status' => 'completed']);
        $today = $this->dashboard();
        $this->assertSame([2, true], [$today['today']['items'][1]['administration'], $today['today']['items'][1]['done']]);
        $this->assertTrue($today['today']['items'][1]['recordedOnly']);
        $this->assertSame(1, $this->cell($today, '2026-08-22')['total']);
        $this->travel(1)->days();
        $this->getJson($this->dayUrl('2026-08-22'))->assertOk()->assertJsonPath('editable', false)
            ->assertJsonPath('items.1.administration', 2)->assertJsonPath('items.1.done', true);
    }

    public function test_a_device_still_in_the_closed_period_has_no_scheduled_doses_on_reopen(): void
    {
        $this->protocol->update(['status' => 'completed']);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-08-25 00:30', 'Europe/Amsterdam'));
        $this->protocol->update(['status' => 'active']);
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard?timezone=America/New_York')->assertOk()
            ->assertJsonPath('date', '2026-08-24')->assertJsonPath('protocol.today.total', 0);
        $this->assertSame(1, $this->dashboard()['today']['total']);
    }

    public function test_only_active_plans_announce_an_upcoming_phase(): void
    {
        $this->protocol->phases()->first()->weeks()->whereIn('protocol_week_number', [1, 2])->delete();
        $this->assertContains('next_phase', array_column($this->dashboard()['notifications'], 'type'));
        foreach (['paused', 'completed'] as $status) {
            $this->protocol->update(['status' => $status]);
            $this->assertNotContains('next_phase', array_column($this->dashboard()['notifications'], 'type'));
        }
    }

    public function test_completion_in_the_owners_timezone_keeps_the_device_local_day_readable(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-08-23 00:30', 'Europe/Amsterdam'));
        $this->given('2026-08-22');
        $this->protocol->update(['status' => 'completed']);
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard?timezone=America/New_York')->assertOk()
            ->assertJsonPath('date', '2026-08-22')->assertJsonPath('protocol.completed', true)
            ->assertJsonPath('protocol.today.done', 1)->assertJsonPath('protocol.today.total', 1);
    }

    public function test_days_after_an_early_completion_are_not_frozen_missed_shown_or_counted(): void
    {
        $this->given('2026-08-20');
        $this->given('2026-08-22');
        $this->protocol->update(['status' => 'completed']);
        $this->assertSame('2026-08-22', $this->protocol->fresh()->completed_on->toDateString(), 'the owner\'s local date, not UTC');

        $this->travelTo(now()->setDate(2026, 8, 28)->setTime(10, 0));
        $protocol = $this->dashboard();
        $this->assertSame(8, $protocol['currentDay']);
        $this->assertSame(2, $protocol['currentWeek']);
        $this->assertSame(29, $protocol['progressPercent']);
        $this->assertSame('complete', $this->cell($protocol, '2026-08-20')['state']);
        $this->assertSame('missed', $this->cell($protocol, '2026-08-21')['state'], 'a real missed plan day stays missed');
        $this->assertSame('complete', $this->cell($protocol, '2026-08-22')['state'], 'the close day keeps its recorded dose');
        foreach (['2026-08-23', '2026-08-25', '2026-08-27'] as $date) {
            $cell = $this->cell($protocol, $date);
            $this->assertSame(['default', 0, 0, false], [$cell['state'], $cell['total'], $cell['done'], $cell['available']], $date);
        }
        $this->assertSame('2026-08-22', DB::table('protocol_day_snapshots')->where('protocol_id', $this->protocol->id)->max('date'));
        $this->getJson($this->dayUrl('2026-08-22'))->assertOk()->assertJsonPath('state', 'complete');
        $this->getJson($this->dayUrl('2026-08-24'))->assertNotFound();
        $home = $this->getJson('/api/home?timezone=Europe/Amsterdam')->assertOk()->json('horseProtocols.0');
        $this->assertSame([8, 29], [$home['currentDay'], $home['progressPercent']]);
    }

    public function test_days_frozen_after_the_completion_before_this_fix_stay_stored_but_only_recorded_doses_show(): void
    {
        $this->protocol->update(['status' => 'completed']);
        $this->travelTo(now()->setDate(2026, 8, 28)->setTime(10, 0));
        $item = fn (bool $done) => json_encode([['id' => 'gone', 'name' => 'Herb', 'dosage' => '20 g', 'administration' => 1, 'administrationsPerDay' => 1, 'done' => $done]]);
        DB::table('protocol_day_snapshots')->insert([
            ['protocol_id' => $this->protocol->id, 'date' => '2026-08-24', 'items' => $item(false), 'created_at' => now(), 'updated_at' => now()],
            ['protocol_id' => $this->protocol->id, 'date' => '2026-08-25', 'items' => $item(true), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $protocol = $this->dashboard();
        $this->assertSame('default', $this->cell($protocol, '2026-08-24')['state']);
        $this->assertSame('complete', $this->cell($protocol, '2026-08-25')['state']);
        $this->getJson($this->dayUrl('2026-08-24'))->assertNotFound();
        $this->getJson($this->dayUrl('2026-08-25'))->assertOk();
        $this->assertSame(2, DB::table('protocol_day_snapshots')->where('protocol_id', $this->protocol->id)->where('date', '>', '2026-08-22')->count(), 'nothing is deleted');
    }

    public function test_a_completed_plan_without_a_known_last_day_infers_nothing_new(): void
    {
        $this->given('2026-08-20');
        $this->protocol->update(['status' => 'completed']);
        Protocol::whereKey($this->protocol->id)->toBase()->update(['completed_on' => null]);
        DB::table('protocol_day_snapshots')->where('protocol_id', $this->protocol->id)->delete();
        $this->travelTo(now()->setDate(2026, 8, 28)->setTime(10, 0));
        $protocol = $this->dashboard();
        $this->assertDatabaseCount('protocol_day_snapshots', 0);
        $this->assertFalse(collect($protocol['calendar']['cells'])->filter()->contains('state', 'missed'));
        $this->assertTrue($this->cell($protocol, '2026-08-20')['available']);
        $this->assertSame('default', $this->cell($protocol, '2026-08-20')['state']);
        $this->assertTrue($this->cell($protocol, '2026-08-20')['recordedOnly']);
        $this->assertFalse($protocol['progressKnown']);
        $this->assertSame($protocol['statusLabel'], $protocol['weekLabel']);
        $this->getJson($this->dayUrl('2026-08-20'))->assertOk()
            ->assertJsonPath('items.0.done', true)->assertJsonPath('items.0.dosage', '20 g');
    }

    public function test_every_status_path_keeps_one_cutoff_rule(): void
    {
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'cutoff@example.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $this->actingAs($admin, 'admin');
        $this->protocol->intake()->update(['submitted_at' => now()]);
        $this->post('/admin/protocols/'.$this->protocol->id.'/status', ['status' => 'completed'])->assertRedirect();
        $this->assertSame('2026-08-22', $this->protocol->fresh()->completed_on?->toDateString());

        // Archive and restore (bulk) keep the original last day, also days later.
        $this->travel(3)->days();
        $this->post('/admin/protocols/bulk', ['action' => 'archive', 'ids' => [$this->protocol->id]])->assertRedirect();
        $this->assertSame('2026-08-22', $this->protocol->fresh()->completed_on?->toDateString());
        $this->post('/admin/protocols/bulk', ['action' => 'restore', 'ids' => [$this->protocol->id]])->assertRedirect();
        $this->assertSame(['completed', '2026-08-22'], [$this->protocol->fresh()->status, $this->protocol->fresh()->completed_on?->toDateString()]);

        // Reopening runs the plan again; completing again records the new day.
        $this->post('/admin/protocols/'.$this->protocol->id.'/status', ['status' => 'active'])->assertRedirect();
        $this->assertNull($this->protocol->fresh()->completed_on);
        $this->given('2026-08-23');
        $gap = $this->dashboard();
        $this->assertSame('default', $this->cell($gap, '2026-08-24')['state']);
        $this->getJson($this->dayUrl('2026-08-24'))->assertNotFound();
        $this->getJson($this->dayUrl('2026-08-23'))->assertOk()->assertJsonPath('editable', false)->assertJsonPath('items.0.done', true);
        $this->post('/admin/protocols/'.$this->protocol->id.'/status', ['status' => 'completed'])->assertRedirect();
        $this->assertSame('2026-08-25', $this->protocol->fresh()->completed_on?->toDateString());
        $this->travel(2)->days();
        $this->assertSame('missed', $this->cell($this->dashboard(), '2026-08-25')['state'], 'the reopening day belongs to the plan');
        $this->assertDatabaseMissing('protocol_day_snapshots', ['protocol_id' => $this->protocol->id, 'date' => '2026-08-24']);
    }

    public function test_existing_completions_use_the_audit_trail_only_where_it_is_reliable(): void
    {
        $other = $this->horse->protocols()->create(['title' => 'Ander plan', 'status' => 'draft']);
        foreach ([$this->protocol, $other] as $protocol) {
            Protocol::whereKey($protocol->id)->toBase()->update(['status' => 'completed', 'completed_on' => null]);
        }
        $audit = fn (Protocol $protocol, string $at, array $before, array $after) => DB::table('audit_logs')->insert(['action' => 'updated',
            'target_type' => 'Protocol', 'target_id' => $protocol->id, 'before' => json_encode($before), 'after' => json_encode($after), 'created_at' => $at]);
        DB::statement("SET LOCAL TIME ZONE 'America/New_York'");
        // An 18:30 database timestamp on the 20th is 00:30 in the owner's Amsterdam timezone.
        $audit($this->protocol, '2026-08-20 18:30:00', ['status' => 'active'], ['status' => 'completed']);
        $audit($this->protocol, '2026-08-21 09:00:00', ['status' => 'completed', 'archived_previous_status' => null], ['status' => 'archived', 'archived_previous_status' => 'completed']);
        $audit($this->protocol, '2026-08-21 10:00:00', ['status' => 'archived', 'archived_previous_status' => 'completed'], ['status' => 'completed', 'archived_previous_status' => null]);
        $audit($this->protocol, '2026-08-21 11:00:00', ['title' => 'Plan'], ['title' => 'Nieuw']);
        // The other plan's last recorded change reopened it: its current completion is not in the trail.
        $audit($other, '2026-08-10 10:00:00', ['status' => 'active'], ['status' => 'completed']);
        $audit($other, '2026-08-11 10:00:00', ['status' => 'completed'], ['status' => 'active']);
        $updatedAt = $this->protocol->fresh()->updated_at;

        (require database_path('migrations/2026_11_18_000000_add_completed_on_to_protocols.php'))->backfill();
        $this->assertSame('2026-08-21', $this->protocol->fresh()->completed_on?->toDateString());
        $this->assertNull($other->fresh()->completed_on, 'no guess from updated_at');
        $this->assertEquals($updatedAt, $this->protocol->fresh()->updated_at);
    }
}
