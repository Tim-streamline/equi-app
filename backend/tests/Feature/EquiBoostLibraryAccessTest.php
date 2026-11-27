<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\{AdminUser, Horse, LibraryItem, Protocol, User};
use App\Support\{CreditLedger, EquiBoostAccess, LibraryDiscovery};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class EquiBoostLibraryAccessTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\HasSubscription;

    private function customer(): User
    {
        $user = User::factory()->create();
        $this->subscribe($user)->update(['paid_through' => now()->addYear()]);
        $this->asUser($user);
        return $user;
    }

    private function asUser(User $user): void
    {
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($user->id) {
            public function __construct(private string $id) {}
            public function handle($request, $next) { $request->attributes->set('powersync_user_id', $this->id); return $next($request); }
        });
    }

    private function item(?string $mode = null): LibraryItem
    {
        return LibraryItem::create(['slug' => (string) Str::uuid(), 'title' => 'Lesson', 'format' => 'article', 'body' => 'Private lesson body', 'published_at' => now()->subDay(), 'equiboost_access' => $mode]);
    }

    private function protocol(User $user, string $status = 'active', bool $published = true): Protocol
    {
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Library access horse', 'status' => 'active']);
        return Protocol::create(['horse_id' => $horse->id, 'title' => 'Personal protocol', 'status' => $status, 'published_at' => $published ? now()->subSecond() : null]);
    }

    private function allowed(User $user, LibraryItem $item): bool
    {
        $service = app(LibraryDiscovery::class);
        return $service->canRead($item->fresh(), $service->access($user));
    }

    public function test_basic_can_purchase_for_the_configured_credit_price_but_cannot_buy_plus_only(): void
    {
        $user = $this->customer();
        $only = $this->item('only');
        $included = $this->item('included');
        $included->update(['credit_cost' => 2]);
        $legacy = $this->item();
        $legacy->update(['is_plus' => true]);
        $this->assertTrue($this->allowed($user, $legacy));
        $this->assertFalse($this->allowed($user, $only));
        $this->getJson('/api/library/'.$only->id)->assertJsonPath('canRead', false)->assertJsonPath('body', null);
        app(CreditLedger::class)->grant($user, 3, 'test');
        $this->postJson('/api/library/'.$only->id.'/unlock', ['credits' => 0])->assertForbidden();
        $this->postJson('/api/library/'.$included->id.'/unlock', ['credits' => 1])->assertStatus(409);
        $this->postJson('/api/library/'.$included->id.'/unlock', ['credits' => 2])->assertOk()->assertJsonPath('canRead', true);
        $this->postJson('/api/library/'.$included->id.'/unlock', ['credits' => 2])->assertOk();
        $this->assertSame(1, app(CreditLedger::class)->summary($user)['balance']);
        $included->update(['equiboost_access' => 'only']);
        $this->assertTrue($this->allowed($user, $included));
        $this->assertDatabaseHas('library_item_access', ['user_id' => $user->id, 'item_id' => $included->id, 'reason' => 'unlocked']);
    }

    public function test_only_published_active_protocols_grant_access_and_pdf_links_recheck_revocation(): void
    {
        Storage::fake('local');
        $user = $this->customer();
        $item = $this->item('only');
        $protocol = $this->protocol($user, 'draft', false);
        $this->assertFalse($this->allowed($user, $item));
        $protocol->update(['published_at' => now()->subSecond(), 'status' => 'paused']);
        $this->assertFalse($this->allowed($user, $item));
        DB::table('protocols')->where('id', $protocol->id)->update(['status' => 'active']);
        $this->assertTrue($this->allowed($user, $item));
        $this->assertDatabaseHas('library_item_access', ['user_id' => $user->id, 'item_id' => $item->id, 'reason' => 'equiboost']);
        Storage::disk('local')->put('boost.pdf', '%PDF-fixture');
        $pdf = $item->attachments()->create(['title' => 'Worksheet', 'name' => 'boost.pdf', 'path' => 'boost.pdf', 'size_bytes' => 12]);
        $link = $this->postJson('/api/library/'.$item->id.'/attachments/'.$pdf->id.'/open')->assertOk()->json('url');
        $this->get($link)->assertOk();
        DB::table('protocols')->where('id', $protocol->id)->update(['status' => 'paused']);
        $this->assertFalse($this->allowed($user, $item));
        $this->assertDatabaseMissing('library_item_access', ['user_id' => $user->id, 'item_id' => $item->id]);
        $this->get($link)->assertForbidden();
    }

    public function test_access_expires_at_three_calendar_months_without_turning_free_access_into_an_unlock(): void
    {
        $user = $this->customer();
        $only = $this->item('only');
        $included = $this->item('included');
        $protocol = $this->protocol($user);
        $start = CarbonImmutable::parse(DB::table('protocols')->where('id', $protocol->id)->value('equiboost_started_at'), 'UTC');
        $expires = $start->addMonthsNoOverflow(3);
        $this->assertTrue($this->allowed($user, $included));
        $this->postJson('/api/library/'.$included->id.'/unlock', ['credits' => 1])->assertOk();
        $this->assertDatabaseMissing('library_unlocks', ['user_id' => $user->id, 'item_id' => $included->id]);
        $this->travelTo($expires->subSecond());
        $this->assertTrue($this->allowed($user, $only));
        $this->travelTo($expires);
        $this->assertFalse($this->allowed($user, $only));
        $this->assertFalse($this->allowed($user, $included));
        DB::statement('SELECT refresh_library_access(?::timestamp)', [now()->format('Y-m-d H:i:s.u')]);
        $this->assertDatabaseMissing('library_item_access', ['user_id' => $user->id, 'item_id' => $only->id]);
        $this->assertDatabaseMissing('library_item_access', ['user_id' => $user->id, 'item_id' => $included->id]);
    }

    public function test_republishing_and_reactivation_cannot_restart_the_three_month_period(): void
    {
        $user = $this->customer();
        $protocol = $this->protocol($user);
        $start = DB::table('protocols')->where('id', $protocol->id)->value('equiboost_started_at');
        $protocol->update(['status' => 'draft', 'published_at' => null]);
        $protocol->update(['status' => 'active', 'published_at' => now()]);
        $this->assertSame($start, DB::table('protocols')->where('id', $protocol->id)->value('equiboost_started_at'));
        DB::table('protocols')->where('id', $protocol->id)->update(['equiboost_started_at' => now()->addMonth()]);
        $this->assertSame($start, DB::table('protocols')->where('id', $protocol->id)->value('equiboost_started_at'));
        $protocol->update(['published_at' => now()->addDay()]);
        $this->assertNull(app(EquiBoostAccess::class)->expiresAt($user->id));
    }

    public function test_a_pause_stops_access_and_its_days_are_added_to_the_three_months(): void
    {
        $user = $this->customer();
        $only = $this->item('only');
        $protocol = $this->protocol($user);
        $start = CarbonImmutable::parse(DB::table('protocols')->where('id', $protocol->id)->value('equiboost_started_at'), 'UTC');
        $protocol->update(['status' => 'paused']);
        $this->assertNotNull(DB::table('protocols')->where('id', $protocol->id)->value('equiboost_paused_at'));
        $this->assertFalse($this->allowed($user, $only));
        $this->assertDatabaseMissing('library_item_access', ['user_id' => $user->id, 'item_id' => $only->id]);

        // Pretend the pause began ten days ago; only the database may set this value.
        DB::statement('SET LOCAL session_replication_role = replica');
        DB::table('protocols')->where('id', $protocol->id)->update(['equiboost_paused_at' => now()->subDays(10)]);
        DB::statement('SET LOCAL session_replication_role = origin');
        $protocol->update(['status' => 'active']);
        $row = DB::table('protocols')->where('id', $protocol->id)->first();
        $this->assertNull($row->equiboost_paused_at);
        $this->assertGreaterThanOrEqual(10 * 86400, $row->equiboost_paused_seconds);
        $this->assertLessThan(10 * 86400 + 120, $row->equiboost_paused_seconds);
        $expires = $start->addMonthsNoOverflow(3)->addSeconds($row->equiboost_paused_seconds);
        $this->assertTrue(app(EquiBoostAccess::class)->expiresAt($user->id)->equalTo($expires));
        $this->assertTrue($this->allowed($user, $only));
        $this->assertNotNull(DB::table('library_item_access')->where(['user_id' => $user->id, 'item_id' => $only->id])->value('expires_at'));
        $synced = CarbonImmutable::parse(DB::table('library_item_access')->where(['user_id' => $user->id, 'item_id' => $only->id])->value('expires_at'), 'UTC');
        $this->assertEqualsWithDelta($expires->getTimestamp(), $synced->getTimestamp(), 1, 'offline access in the app ends at the same moment');

        $this->travelTo($start->addMonthsNoOverflow(3)->addDays(5));
        $this->assertTrue($this->allowed($user, $only), 'the paused days are still to come');
        $this->travelTo($expires);
        $this->assertFalse($this->allowed($user, $only));

        // The counters cannot be changed by hand.
        DB::table('protocols')->where('id', $protocol->id)->update(['equiboost_paused_seconds' => 999999999, 'equiboost_paused_at' => now()]);
        $this->assertSame($row->equiboost_paused_seconds, DB::table('protocols')->where('id', $protocol->id)->value('equiboost_paused_seconds'));
        $this->assertNull(DB::table('protocols')->where('id', $protocol->id)->value('equiboost_paused_at'));
    }

    public function test_a_plan_paused_before_its_equiboost_start_adds_nothing(): void
    {
        $user = $this->customer();
        $protocol = $this->protocol($user, 'paused');
        $this->assertNull(DB::table('protocols')->where('id', $protocol->id)->value('equiboost_paused_at'));
        $protocol->update(['status' => 'active']);
        $this->assertSame(0, (int) DB::table('protocols')->where('id', $protocol->id)->value('equiboost_paused_seconds'));
    }

    public function test_earlier_pauses_are_counted_from_the_audit_log(): void
    {
        $user = $this->customer();
        $resumed = $this->protocol($user);
        $stillPaused = $this->protocol($user);
        $unknown = $this->protocol($user);
        DB::table('protocols')->whereIn('id', [$stillPaused->id, $unknown->id])->update(['status' => 'paused']);
        $log = fn (Protocol $p, string $from, string $to, CarbonImmutable $at) => DB::table('audit_logs')->insert(['action' => 'updated', 'target_type' => 'Protocol',
            'target_id' => $p->id, 'before' => json_encode(['status' => $from]), 'after' => json_encode(['status' => $to]), 'created_at' => $at]);
        $now = CarbonImmutable::now('UTC');
        $log($resumed, 'active', 'paused', $now->addDays(1));
        $log($resumed, 'paused', 'active', $now->addDays(4));
        $log($resumed, 'active', 'paused', $now->addDays(6));
        $log($resumed, 'paused', 'archived', $now->addDays(8));
        $log($stillPaused, 'active', 'paused', $now->addDays(2));

        $migration = require database_path('migrations/2026_11_26_000002_add_equiboost_pause_time_to_protocols.php');
        DB::statement('SET LOCAL session_replication_role = replica');
        (fn () => $this->backfillFromAuditLog())->call($migration);
        DB::statement('SET LOCAL session_replication_role = origin');

        $this->assertEqualsWithDelta(5 * 86400, (int) DB::table('protocols')->where('id', $resumed->id)->value('equiboost_paused_seconds'), 1);
        $this->assertNull(DB::table('protocols')->where('id', $resumed->id)->value('equiboost_paused_at'));
        $this->assertEqualsWithDelta($now->addDays(2)->getTimestamp(), CarbonImmutable::parse(DB::table('protocols')->where('id', $stillPaused->id)->value('equiboost_paused_at'), 'UTC')->getTimestamp(), 1);
        $this->assertNotNull(DB::table('protocols')->where('id', $unknown->id)->value('equiboost_paused_at'), 'a pause without a record counts from today');
    }

    public function test_horse_transfer_archive_and_subscription_expiry_reconcile_sync_access(): void
    {
        $original = $this->customer();
        $newOwner = $this->customer();
        $item = $this->item('only');
        $protocol = $this->protocol($original);
        $this->assertTrue($this->allowed($original, $item));
        $this->assertFalse($this->allowed($newOwner, $item));
        DB::table('horses')->where('id', $protocol->horse_id)->update(['owner_id' => $newOwner->id]);
        $this->assertFalse($this->allowed($original, $item));
        $this->assertTrue($this->allowed($newOwner, $item));
        $this->assertDatabaseMissing('library_item_access', ['user_id' => $original->id, 'item_id' => $item->id]);
        $this->assertDatabaseHas('library_item_access', ['user_id' => $newOwner->id, 'item_id' => $item->id]);
        DB::table('horses')->where('id', $protocol->horse_id)->update(['status' => 'archived']);
        $this->assertFalse($this->allowed($newOwner, $item));
        $this->assertDatabaseMissing('library_item_access', ['user_id' => $newOwner->id, 'item_id' => $item->id]);
    }

    public function test_admin_can_select_both_equiboost_modes_without_reclassifying_existing_items(): void
    {
        $admin = AdminUser::create(['name' => 'Editor', 'email' => 'boost-library@example.test', 'password' => 'password', 'role' => 'content_editor', 'active' => true]);
        $legacy = $this->item();
        $this->actingAs($admin, 'admin')->post('/admin/library', ['title' => 'Boost combo', 'format' => 'article', 'equiboost_access' => 'included', 'is_plus' => true, 'credit_cost' => 8])->assertSessionHasNoErrors();
        $item = LibraryItem::where('title', 'Boost combo')->sole();
        $this->assertSame(8, (int) $item->credit_cost);
        $this->assertFalse($item->is_plus);
        $this->assertNull($legacy->fresh()->equiboost_access);
        $this->put('/admin/library/'.$item->id, ['title' => 'Boost combo', 'format' => 'article', 'equiboost_access' => 'only'])->assertSessionHasNoErrors();
        $this->assertSame(0, (int) $item->fresh()->credit_cost);
        $this->put('/admin/library/'.$item->id, ['title' => 'Boost combo', 'format' => 'article', 'equiboost_access' => null, 'is_plus' => true])->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->equiboost_access);
        $this->assertTrue($item->fresh()->is_plus);
    }
}
