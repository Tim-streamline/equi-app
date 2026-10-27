<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\LibraryItem;
use App\Models\Protocol;
use App\Models\User;
use App\Support\CreditLedger;
use App\Support\HorseDeletion;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\Concerns\HasSubscription;
use Tests\TestCase;

class LibraryLockOrderTest extends TestCase
{
    // Committed fixtures are essential: a wrapping test transaction would
    // already hold the advisory lock acquired by the fixture insert triggers.
    use DatabaseMigrations;

    /**
     * Rebuild instead of rolling back afterwards: some migrations refuse a rollback once data
     * exists, which used to leave a partial schema for every test that ran after this class.
     */
    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(fn () => \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false);
    }
    use HasSubscription;

    #[TestWith(['draft', true])]
    #[TestWith(['paused', false])]
    public function test_admin_booking_deletion_holds_the_sync_lock_before_locking_the_booking(string $status, bool $deletable): void
    {
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'booking-lock@example.test',
            'password' => 'password', 'role' => 'owner', 'active' => true]);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Booking lock test', 'status' => 'active']);
        $protocol = Protocol::create(['horse_id' => $horse->id, 'title' => 'Booking protocol', 'status' => $status]);
        $bookingId = $protocol->intake->id;

        config(['database.connections.library_lock_probe' => config('database.connections.'.config('database.default'))]);
        $probe = DB::connection('library_lock_probe');
        $canAcquire = fn () => $probe->selectOne('SELECT pg_try_advisory_xact_lock(724119301) AS acquired')->acquired;
        $this->assertTrue($canAcquire(), 'Fixture transactions must release their locks.');
        $heldBeforeBookingLock = [];
        $checking = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$heldBeforeBookingLock, &$checking, $canAcquire): void {
            if ($checking && preg_match('/from "intake_bookings".*for update/is', $sql)) {
                // Observe from another connection before the endpoint takes its first row lock.
                $heldBeforeBookingLock[] = ! $canAcquire();
            }
        });

        try {
            $response = $this->actingAs($admin, 'admin')->delete('/admin/bookings/'.$bookingId, ['confirm_delete' => true]);
            $this->assertNotEmpty($heldBeforeBookingLock, 'The endpoint must exercise the booking row lock.');
            $this->assertNotContains(false, $heldBeforeBookingLock, 'Booking deletion must hold the library lock before locking the booking.');
            $this->assertTrue($canAcquire(), 'Deletion must release the advisory lock on commit or rollback.');
            if ($deletable) {
                $response->assertRedirect(route('admin.bookings.index'))->assertSessionHasNoErrors();
                $this->assertDatabaseMissing('intake_bookings', ['id' => $bookingId]);
                $this->assertDatabaseMissing('protocols', ['id' => $protocol->id]);
            } else {
                $response->assertSessionHasErrors('booking_delete');
                $this->assertDatabaseHas('intake_bookings', ['id' => $bookingId]);
                $this->assertDatabaseHas('protocols', ['id' => $protocol->id]);
            }
            $this->assertDatabaseHas('horses', ['id' => $horse->id]);
        } finally {
            $checking = false;
            DB::purge('library_lock_probe');
        }
    }

    public function test_horse_deletion_holds_the_sync_lock_before_locking_the_horse(): void
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Deletion lock test', 'status' => 'active']);
        $protocol = Protocol::create(['horse_id' => $horse->id, 'title' => 'Draft protocol', 'status' => 'draft']);
        $bookingId = $protocol->intake->id;

        config(['database.connections.library_lock_probe' => config('database.connections.'.config('database.default'))]);
        $probe = DB::connection('library_lock_probe');
        $canAcquire = fn () => $probe->selectOne('SELECT pg_try_advisory_xact_lock(724119301) AS acquired')->acquired;
        $this->assertTrue($canAcquire(), 'Fixture transactions must release their locks.');
        $checked = false;
        $checking = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$checked, &$checking, $canAcquire): void {
            if ($checking && preg_match('/from "horses".*for update/is', $sql)) {
                $this->assertFalse($canAcquire(), 'Horse deletion must hold the library lock before locking the horse.');
                $checked = true;
            }
        });

        try {
            app(HorseDeletion::class)->delete($horse);
            $this->assertTrue($checked, 'Deletion must exercise the horse row lock.');
            $this->assertTrue($canAcquire(), 'Deletion must release the advisory lock on commit.');
            $this->assertDatabaseMissing('horses', ['id' => $horse->id]);
            $this->assertDatabaseMissing('protocols', ['id' => $protocol->id]);
            $this->assertDatabaseMissing('intake_bookings', ['id' => $bookingId]);
        } finally {
            $checking = false;
            DB::purge('library_lock_probe');
        }
    }

    public function test_library_mutations_hold_the_sync_lock_before_locking_source_rows(): void
    {
        $this->withoutExceptionHandling();
        $admin = AdminUser::create(['name' => 'Editor', 'email' => 'locking@example.test',
            'password' => 'password', 'role' => 'content_editor', 'active' => true]);
        $this->actingAs($admin, 'admin');
        $item = LibraryItem::create(['title' => 'First', 'slug' => 'first', 'format' => 'article',
            'order' => 10, 'credit_cost' => 2, 'published_at' => now()->subDay()]);
        $neighbor = LibraryItem::create(['title' => 'Second', 'slug' => 'second', 'format' => 'article',
            'order' => 20, 'published_at' => now()->subDay()]);
        $user = User::factory()->create();
        $this->subscribe($user);
        app(CreditLedger::class)->grant($user, 5, 'test');
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($user->id)
        {
            public function __construct(private string $id) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->id);

                return $next($request);
            }
        });

        config(['database.connections.library_lock_probe' => config('database.connections.'.config('database.default'))]);
        $probe = DB::connection('library_lock_probe');
        $canAcquire = fn () => $probe->selectOne('SELECT pg_try_advisory_xact_lock(724119301) AS acquired')->acquired;
        $this->assertTrue($canAcquire(), 'Fixture transactions must release their locks.');
        $checked = 0;
        $checking = true;
        $awaitingUserLock = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$checked, &$checking, &$awaitingUserLock, $canAcquire): void {
            if ($checking && $awaitingUserLock && preg_match('/from "users".*for update/is', $sql)) {
                $this->assertTrue($canAcquire(), 'Unlock must not hold the advisory lock while waiting for the user.');
                $awaitingUserLock = false;
            }
            if ($checking && preg_match('/from "library_items".*for update/is', $sql)) {
                // A separate PostgreSQL session must already be excluded before
                // this request acquires any of the source row locks.
                $this->assertFalse($canAcquire(), 'A competing library writer can acquire the advisory lock before this row lock.');
                $checked++;
            }
        });

        $url = '/admin/library/'.$item->id;
        try {
            $actions = [
                fn () => $this->patch($url.'/order', ['order' => 5])->assertRedirect()->assertSessionHasNoErrors(),
                fn () => $this->patch($url.'/move', ['neighbor_id' => $neighbor->id, 'direction' => 'down'])
                    ->assertRedirect()->assertSessionHasNoErrors(),
                fn () => $this->put($url, ['title' => 'Edited', 'format' => 'article'])
                    ->assertRedirect()->assertSessionHasNoErrors(),
                function () use ($item, &$awaitingUserLock): void {
                    $awaitingUserLock = true;
                    $this->postJson('/api/library/'.$item->id.'/unlock', ['credits' => 2])->assertOk();
                    $this->assertFalse($awaitingUserLock, 'Unlock must lock the user row.');
                },
                fn () => $this->post($url.'/unpublish')->assertRedirect()->assertSessionHasNoErrors(),
            ];
            foreach ($actions as $action) {
                $before = $checked;
                $action();
                $this->assertGreaterThan($before, $checked, 'The request must exercise a source row lock.');
                $this->assertTrue($canAcquire(), 'The request must release the advisory lock on commit.');
            }

            $this->withExceptionHandling();
            $this->patch($url.'/move', ['neighbor_id' => $neighbor->id, 'direction' => 'down'])
                ->assertSessionHasErrors('order');
            $this->assertTrue($canAcquire(), 'A rejected move must release the advisory lock on rollback.');
        } finally {
            $checking = false;
            DB::purge('library_lock_probe');
        }
    }

    public function test_unlock_waiting_on_subscription_renewal_completes_without_deadlock_or_double_spend(): void
    {
        $this->withoutExceptionHandling();
        $user = User::factory()->create();
        $subscription = $this->subscribe($user);
        $subscription->update(['paid_through' => now()->subDay()]);
        $item = LibraryItem::create(['title' => 'Concurrent unlock', 'slug' => 'concurrent-unlock',
            'format' => 'article', 'credit_cost' => 2, 'published_at' => now()->subDay()]);
        app(CreditLedger::class)->grant($user, 5, 'test');
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($user->id)
        {
            public function __construct(private string $id) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->id);

                return $next($request);
            }
        });

        // A separate PHP process exercises the real unlock controller and its
        // transaction. Pass the test connection explicitly, never the .env DB.
        $worker = new Process([PHP_BINARY, '-r', <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
            config(['database.default' => 'unlock_test', 'database.connections.unlock_test' => $input['connection']]);
            $db = Illuminate\Support\Facades\DB::connection();
            $db->statement("SET statement_timeout = '10s'");
            echo $db->selectOne('SELECT pg_backend_pid() AS pid')->pid."\n";
            flush();
            $request = Illuminate\Http\Request::create('/api/library/'.$input['item'].'/unlock', 'POST', ['credits' => 2]);
            $request->attributes->set('powersync_user_id', $input['user']);
            $response = app(App\Http\Controllers\LibraryController::class)->unlock(
                $request, App\Models\LibraryItem::findOrFail($input['item']), app(App\Support\LibraryDiscovery::class)
            );
            echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getData(true)], JSON_THROW_ON_ERROR)."\n";
            PHP], base_path(), ['APP_ENV' => 'testing', 'BETTER_STACK_DSN' => '', 'NIGHTWATCH_ENABLED' => 'false']);
        $worker->setInput(json_encode(['connection' => config('database.connections.'.config('database.default')),
            'user' => $user->id, 'item' => $item->id], JSON_THROW_ON_ERROR));
        $worker->setTimeout(15);
        config(['database.connections.renewal_probe' => config('database.connections.'.config('database.default'))]);
        $probe = DB::connection('renewal_probe');
        $renewalPid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $started = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($worker, $probe, $renewalPid, &$started): void {
            if ($started || ! str_starts_with($sql, 'update "subscriptions"')) {
                return;
            }
            // Renewal holds the user lock here, but has not yet run the write
            // whose trigger acquires the advisory lock. Force the overlap.
            $started = true;
            $worker->start();
            $deadline = microtime(true) + 5;
            do {
                $this->assertTrue($worker->isRunning(), $worker->getErrorOutput().$worker->getOutput());
                $pid = (int) strtok($worker->getOutput(), "\n");
                if ($pid && $probe->selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS blocked', [$renewalPid, $pid])->blocked) {
                    return;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->fail('Unlock did not reach the user lock held by renewal.');
        });

        try {
            $this->postJson('/api/subscription/renew')->assertOk()->assertJsonPath('subscribed', true);
            $this->assertTrue($started, 'Renewal must exercise a subscription update.');
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            $lines = explode("\n", trim($worker->getOutput()));
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(200, $result['status']);
            $this->assertTrue($result['body']['canRead']);
            $this->assertNull($subscription->fresh()->paid_through);
            // A retry must keep the permanent unlock and debit exactly once.
            $this->postJson('/api/library/'.$item->id.'/unlock', ['credits' => 2])->assertOk();
            $this->assertSame(1, DB::table('library_unlocks')->where('user_id', $user->id)->where('item_id', $item->id)->count());
            $spends = DB::table('credit_transactions')->where('user_id', $user->id)->where('type', 'spent');
            $this->assertSame(1, $spends->count());
            $this->assertSame(-2, (int) $spends->sum('amount'));
            $this->assertSame(3, (int) DB::table('credit_transactions')->where('user_id', $user->id)->sum('amount'));
        } finally {
            $started = true;
            if ($worker->isRunning()) {
                $worker->stop(0);
            }
            DB::purge('renewal_probe');
        }
    }
}
