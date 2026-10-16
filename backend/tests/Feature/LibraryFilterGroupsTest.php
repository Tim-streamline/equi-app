<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\AdminUser;
use App\Models\LibraryCategory;
use App\Models\LibraryFilterGroup;
use App\Models\LibraryItem;
use App\Models\LibraryProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class LibraryFilterGroupsTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\HasSubscription;

    private function item(): LibraryItem
    {
        return LibraryItem::create(['slug' => (string) Str::uuid(), 'title' => 'Test lesson', 'format' => 'article', 'published_at' => now()->subDay()]);
    }

    public function test_admin_manages_groups_values_and_archives_without_removing_item_links(): void
    {
        $admin = AdminUser::create(['name' => 'Editor', 'email' => 'filters@example.test', 'password' => 'password', 'role' => 'content_editor', 'active' => true]);
        $this->actingAs($admin, 'admin')->post('/admin/library-filter-groups', ['label' => 'Niveau', 'order' => 4, 'active' => true])->assertSessionHasNoErrors();
        $group = LibraryFilterGroup::where('label', 'Niveau')->sole();
        $this->post('/admin/library-categories', ['label' => 'Basis', 'group_id' => $group->id, 'order' => 2, 'active' => true])->assertSessionHasNoErrors();
        $category = LibraryCategory::where('label', 'Basis')->sole();
        $item = $this->item();
        $item->categories()->attach($category);
        $this->put('/admin/library-filter-groups/'.$group->id, ['label' => 'Kennisniveau', 'order' => 0, 'active' => true])->assertSessionHasNoErrors();
        $this->assertSame('Kennisniveau', $group->fresh()->label);
        $this->assertSame(0, $group->fresh()->order);
        $this->post('/admin/library-filter-groups', ['label' => 'Niveau', 'order' => 4, 'active' => true])->assertSessionHasErrors('label');
        $this->put('/admin/library-categories/'.$category->id, ['label' => 'Beginner', 'group_id' => $group->id, 'order' => 1, 'active' => true])->assertSessionHasNoErrors();
        $this->delete('/admin/library-categories/'.$category->id)->assertSessionHasNoErrors();
        $this->assertFalse($category->fresh()->active);
        $this->assertSame([$category->id], $item->categories()->pluck('library_categories.id')->all());
        $this->put('/admin/library-filter-groups/'.$group->id, ['label' => 'Kennisniveau', 'order' => 0, 'active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($group->fresh()->active);
        $this->post('/admin/library-filter-groups', ['label' => ' kennisniveau ', 'order' => 0, 'active' => true])->assertSessionHasErrors('label');
        $this->post('/admin/library-filter-groups', ['label' => 'Persoonlijk', 'order' => 0, 'active' => true])->assertSessionHasErrors('label');
        $complaints = LibraryFilterGroup::where('slug', 'klachten')->sole();
        foreach (['Darm', 'Darmgezondheid', 'DARMEN'] as $label) {
            $this->post('/admin/library-categories', ['label' => $label, 'group_id' => $complaints->id, 'slug' => (string) Str::uuid()])->assertSessionHasErrors('label');
        }
        $this->get('/admin/library/'.$item->id.'/edit')->assertOk();
    }

    public function test_migration_merges_known_synonyms_and_preserves_custom_categories_and_assignments(): void
    {
        LibraryCategory::query()->delete();
        Schema::table('library_categories', function ($table) {
            $table->dropConstrainedForeignId('group_id');
            $table->dropColumn('active');
        });
        Schema::drop('library_filter_groups');
        $gut = LibraryCategory::create(['label' => 'Darm', 'slug' => 'darm']);
        $duplicate = LibraryCategory::create(['label' => 'Darmgezondheid', 'slug' => 'darmgezondheid']);
        $custom = LibraryCategory::create(['label' => 'Eigen onderwerp', 'slug' => 'eigen']);
        $a = $this->item();
        $b = $this->item();
        $a->categories()->attach([$gut->id, $duplicate->id, $custom->id]);
        $b->categories()->attach($duplicate->id);
        $migration = require database_path('migrations/2026_10_16_000004_group_library_filters.php');
        $migration->up();
        $canonical = LibraryCategory::where('slug', 'darmen')->sole();
        $this->assertTrue($canonical->active);
        $this->assertSame('Klachten', $canonical->group->label);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $canonical->items()->pluck('library_items.id')->all());
        $this->assertSame(1, LibraryCategory::where('active', true)->whereIn('label', ['Darmen', 'Darm', 'Darmgezondheid'])->count());
        $this->assertSame('Eigen onderwerp', $custom->fresh()->label);
        $this->assertSame([$a->id], $custom->items()->pluck('library_items.id')->all());
    }

    public function test_view_history_merges_offline_devices_keeps_latest_time_and_rejects_other_owners(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($user->id)
        {
            public function __construct(private string $id) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->id);

                return $next($request);
            }
        });
        $item = $this->item();
        $firstId = (string) Str::uuid();
        $write = fn ($id, $owner, $date) => $this->postJson('/api/sync/upload', ['operations' => [['op' => 'PUT', 'type' => 'library_progress', 'id' => $id, 'data' => ['user_id' => $owner, 'item_id' => $item->id, 'last_viewed_at' => $date]]]]);
        $write($firstId, $user->id, '2026-10-15T10:00:00Z')->assertOk();
        LibraryProgress::findOrFail($firstId)->update(['progress' => .8, 'position_sec' => 120]);
        $write((string) Str::uuid(), $user->id, '2026-10-14T10:00:00Z')->assertOk();
        $view = LibraryProgress::where('user_id', $user->id)->sole();
        $this->assertSame('2026-10-15', $view->last_viewed_at->toDateString());
        $this->assertSame(.8, $view->progress);
        $this->assertSame(120, $view->position_sec);
        $write((string) Str::uuid(), User::factory()->create()->id, '2026-10-16T10:00:00Z')->assertForbidden();
        $this->postJson('/api/sync/upload', ['operations' => [['op' => 'PATCH', 'type' => 'library_filter_groups', 'id' => LibraryFilterGroup::first()->id, 'data' => ['label' => 'Unauthorized']]]])->assertForbidden();
    }
}
