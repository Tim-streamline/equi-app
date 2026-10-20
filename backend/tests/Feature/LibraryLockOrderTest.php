<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\AdminUser;
use App\Models\LibraryItem;
use App\Models\User;
use App\Support\CreditLedger;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasSubscription;
use Tests\TestCase;

class LibraryLockOrderTest extends TestCase
{
    // Committed fixtures are essential: a wrapping test transaction would
    // already hold the advisory lock acquired by the fixture insert triggers.
    use DatabaseMigrations;
    use HasSubscription;

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
        DB::connection()->beforeExecuting(function (string $sql) use (&$checked, &$checking, $canAcquire): void {
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
                fn () => $this->postJson('/api/library/'.$item->id.'/unlock', ['credits' => 2])->assertOk(),
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
}
