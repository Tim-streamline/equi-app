<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\BewegingAdvies;
use App\Models\Horse;
use App\Models\ManagementAdvies;
use App\Models\Protocol;
use App\Models\User;
use App\Models\VoedingAdvies;
use App\Support\ProtocolMeasures;
use Database\Seeders\ManagementAdviesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminProtocolMeasuresTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Start from an empty library: migrations install the EquiBoost defaults.
        foreach (ProtocolMeasures::CATEGORIES as $config) {
            DB::table($config['table'])->delete();
        }
        $this->admin = $this->adminWithRole('admin');
    }

    public function test_index_lists_every_category_in_library_order_with_usage(): void
    {
        $second = ManagementAdvies::create(['title' => 'Aaa tweede', 'description' => 'x', 'sort_order' => 2]);
        $first = ManagementAdvies::create(['title' => 'Zzz eerste', 'description' => 'x', 'sort_order' => 1]);
        $voeding = VoedingAdvies::create(['title' => 'Ruwvoer', 'description' => 'x', 'sort_order' => 1]);
        $this->snapshot($this->protocol(), 'management', $first);

        $this->actingAs($this->admin, 'admin')->get('/admin/protocol-measures')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProtocolMeasures/Index')
                ->where('categories', fn ($rows) => collect($rows)->pluck('key')->all() === ['voeding', 'management', 'beweging'])
                ->where('measures', fn ($rows) => collect($rows)->map(fn ($row) => [$row['id'], $row['category'], $row['usage_count']])->all() === [
                    [$voeding->id, 'voeding', 0],
                    [$first->id, 'management', 1],
                    [$second->id, 'management', 0],
                ]));
    }

    public function test_old_settings_url_redirects_to_the_measure_library(): void
    {
        $this->actingAs($this->admin, 'admin')->get('/admin/protocol-settings/advice')->assertRedirect('/admin/protocol-measures');
    }

    public function test_content_editors_and_admins_manage_measures_other_roles_do_not(): void
    {
        foreach (['admin', 'content_editor', 'owner'] as $role) {
            $this->actingAs($this->adminWithRole($role), 'admin')->get('/admin/protocol-measures')->assertOk();
        }
        $moderator = $this->adminWithRole('moderator');
        $this->actingAs($moderator, 'admin')->get('/admin/protocol-measures')->assertForbidden();
        $this->actingAs($moderator, 'admin')->post('/admin/protocol-measures', $this->fields('voeding'))->assertForbidden();
        $this->assertSame(0, VoedingAdvies::count());
    }

    public function test_new_measures_are_appended_to_their_category_and_offered_in_the_editor(): void
    {
        VoedingAdvies::create(['title' => 'Bestaand', 'description' => 'x', 'sort_order' => 4]);

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-measures', $this->fields('voeding', ['title' => 'Nieuw', 'layout' => 'roughage']))
            ->assertSessionHasNoErrors();

        $measure = VoedingAdvies::where('title', 'Nieuw')->sole();
        $this->assertSame(5, $measure->sort_order);
        $this->assertSame('roughage', $measure->layout);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $measure->id, 'action' => 'created']);

        $this->get('/admin/protocols/create')->assertInertia(fn (Assert $page) => $page
            ->where('voedingAdviezen', fn ($rows) => collect($rows)->pluck('title')->all() === ['Bestaand', 'Nieuw'])
            ->where('managementAdviezen', []));
    }

    public function test_store_validates_fields_and_layouts_per_category(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-measures', ['category' => 'voeding', 'title' => '', 'description' => '', 'layout' => 'normal'])
            ->assertSessionHasErrors(['title', 'description']);
        $this->post('/admin/protocol-measures', $this->fields('onbekend'))->assertSessionHasErrors('category');
        $this->post('/admin/protocol-measures', $this->fields('management', ['layout' => 'roughage']))->assertSessionHasErrors('layout');

        foreach (['normal', 'link_to_library', 'roughage', 'supplementary_feed'] as $layout) {
            $this->post('/admin/protocol-measures', $this->fields('voeding', ['title' => $layout, 'layout' => $layout]))->assertSessionHasNoErrors();
        }
    }

    public function test_editor_offers_measures_in_library_order(): void
    {
        BewegingAdvies::create(['title' => 'A', 'description' => 'x', 'sort_order' => 3]);
        BewegingAdvies::create(['title' => 'B', 'description' => 'x', 'sort_order' => 1]);
        BewegingAdvies::create(['title' => 'C', 'description' => 'x', 'sort_order' => 2])->forceFill(['archived_at' => now()])->save();

        $this->actingAs($this->admin, 'admin')->get('/admin/protocols/create')->assertInertia(fn (Assert $page) => $page
            ->where('bewegingAdviezen', fn ($rows) => collect($rows)->pluck('title')->all() === ['B', 'A']));
    }

    public function test_measures_move_up_and_down_within_their_active_category(): void
    {
        [$a, $b, $c] = collect(['A', 'B', 'C'])->map(fn ($title, $i) => ManagementAdvies::create(['title' => $title, 'description' => 'x', 'sort_order' => ($i + 1) * 10]))->all();
        $archived = ManagementAdvies::create(['title' => 'Archief', 'description' => 'x', 'sort_order' => 15]);
        $archived->forceFill(['archived_at' => now()])->save();
        $url = fn ($measure) => "/admin/protocol-measures/management/{$measure->id}/move";

        $this->actingAs($this->admin, 'admin')->patch($url($c), ['direction' => 'up'])->assertSessionHasNoErrors();
        $this->assertSame(['A', 'C', 'B'], ManagementAdvies::whereNull('archived_at')->ordered()->pluck('title')->all());
        $this->assertSame([1, 2, 3], ManagementAdvies::whereNull('archived_at')->ordered()->pluck('sort_order')->all());
        $this->assertDatabaseHas('audit_logs', ['target_id' => $c->id, 'action' => 'reordered']);

        $this->patch($url($a), ['direction' => 'up'])->assertSessionHasNoErrors();
        $this->patch($url($a), ['direction' => 'down'])->assertSessionHasNoErrors();
        $this->assertSame(['C', 'A', 'B'], ManagementAdvies::whereNull('archived_at')->ordered()->pluck('title')->all());

        $this->patch($url($archived), ['direction' => 'up'])->assertSessionHasErrors('measure');
        $this->patch($url($b), ['direction' => 'sideways'])->assertSessionHasErrors('direction');
    }

    public function test_archived_measures_are_restored_at_the_end_of_their_category(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('measure.svg', 'icon');
        $measure = ManagementAdvies::create(['title' => 'Archiveren', 'description' => 'x', 'icon' => 'measure.svg', 'sort_order' => 1]);
        ManagementAdvies::create(['title' => 'Ander', 'description' => 'x', 'sort_order' => 2]);
        $url = "/admin/protocol-measures/management/{$measure->id}";

        $this->actingAs($this->admin, 'admin')->post("{$url}/archive")->assertSessionHas('success');
        $this->assertNotNull($measure->fresh()->archived_at);
        Storage::disk('public')->assertExists('measure.svg');
        $this->assertDatabaseHas('audit_logs', ['target_id' => $measure->id, 'action' => 'archived']);

        $this->post("{$url}/restore")->assertSessionHas('success');
        $this->assertNull($measure->fresh()->archived_at);
        $this->assertSame(['Ander', 'Archiveren'], ManagementAdvies::ordered()->pluck('title')->all());
        $this->assertDatabaseHas('audit_logs', ['target_id' => $measure->id, 'action' => 'restored']);
    }

    public function test_unused_measures_are_deleted_with_their_icon(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('measure.svg', 'icon');
        $measure = BewegingAdvies::create(['title' => 'Weg', 'description' => 'x', 'icon' => 'measure.svg']);

        $this->actingAs($this->admin, 'admin')->delete("/admin/protocol-measures/beweging/{$measure->id}")->assertSessionHas('success');

        $this->assertModelMissing($measure);
        Storage::disk('public')->assertMissing('measure.svg');
        $this->assertDatabaseHas('audit_logs', ['target_id' => $measure->id, 'action' => 'deleted']);
    }

    public function test_measures_a_protocol_refers_to_cannot_be_deleted(): void
    {
        $snapshotted = VoedingAdvies::create(['title' => 'Geselecteerd', 'description' => 'x']);
        $overridden = VoedingAdvies::create(['title' => 'Alleen override', 'description' => 'x']);
        $protocol = $this->protocol(['customer_settings' => ['nutrition_advice' => [['id' => strtoupper($overridden->id)]]]]);
        $this->snapshot($protocol, 'voeding', $snapshotted);
        // Archived protocols still refer to their measures.
        $archived = $this->protocol(['status' => 'archived']);
        $this->snapshot($archived, 'voeding', $snapshotted);

        $this->actingAs($this->admin, 'admin');
        $this->delete("/admin/protocol-measures/voeding/{$snapshotted->id}")->assertSessionHasErrors(['measure' => str_replace(':count', '2', 'Deze maatregel wordt gebruikt in :count protocol(len) en kan niet worden verwijderd. Archiveer hem in plaats daarvan.')]);
        $this->delete("/admin/protocol-measures/voeding/{$overridden->id}")->assertSessionHasErrors('measure');

        $this->assertModelExists($snapshotted);
        $this->assertModelExists($overridden);
    }

    public function test_usage_counts_distinct_protocols_across_snapshots_and_overrides(): void
    {
        $measure = BewegingAdvies::create(['title' => 'Beweging', 'description' => 'x']);
        $both = $this->protocol(['customer_settings' => ['movement' => [['id' => $measure->id, 'action' => 'avoid']], 'management' => 'geen lijst']]);
        $this->snapshot($both, 'beweging', $measure);
        $this->protocol(['customer_settings' => ['movement' => [['id' => $measure->id]]]]);
        // An id in another category's group is not a reference to this measure.
        $this->protocol(['customer_settings' => ['nutrition_advice' => [['id' => $measure->id]]]]);

        $this->assertSame(2, ProtocolMeasures::usageCount('beweging', $measure->id));
        $this->assertSame(0, ProtocolMeasures::usageCount('management', $measure->id));
    }

    public function test_unused_measures_move_to_another_category_keeping_their_identity(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('measure.svg', 'icon');
        BewegingAdvies::create(['title' => 'Bestaand', 'description' => 'x', 'sort_order' => 7]);
        $measure = ManagementAdvies::create(['title' => 'Verplaatsen', 'description' => 'x', 'icon' => 'measure.svg', 'sort_order' => 1]);
        $measure->forceFill(['created_at' => now()->subYear(), 'archived_at' => now()->subDay()])->save();
        $created = $measure->fresh()->created_at;

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/protocol-measures/management/{$measure->id}", $this->fields('beweging', ['title' => 'Verplaatst']))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($measure);
        $moved = BewegingAdvies::findOrFail($measure->id);
        $this->assertSame('Verplaatst', $moved->title);
        $this->assertSame('measure.svg', $moved->icon);
        $this->assertSame(8, $moved->sort_order);
        $this->assertNotNull($moved->archived_at);
        $this->assertTrue($created->equalTo($moved->created_at));
        Storage::disk('public')->assertExists('measure.svg');
        $log = DB::table('audit_logs')->where('target_id', $measure->id)->where('action', 'moved')->sole();
        $this->assertSame('management', json_decode($log->before, true)['category']);
        $this->assertSame('beweging', json_decode($log->after, true)['category']);
    }

    public function test_moving_validates_the_layout_against_the_target_category(): void
    {
        $measure = VoedingAdvies::create(['title' => 'Ruwvoer', 'description' => 'x', 'layout' => 'roughage']);

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/protocol-measures/voeding/{$measure->id}", $this->fields('management', ['layout' => 'roughage']))
            ->assertSessionHasErrors('layout');
        $this->assertModelExists($measure);
    }

    public function test_measures_a_protocol_refers_to_keep_their_category(): void
    {
        $snapshotted = VoedingAdvies::create(['title' => 'Geselecteerd', 'description' => 'x']);
        $overridden = VoedingAdvies::create(['title' => 'Override', 'description' => 'x']);
        $this->snapshot($this->protocol(), 'voeding', $snapshotted);
        $this->protocol(['customer_settings' => ['nutrition_advice' => [['id' => $overridden->id, 'instruction' => 'x']]]]);

        $this->actingAs($this->admin, 'admin');
        foreach ([$snapshotted, $overridden] as $measure) {
            $this->put("/admin/protocol-measures/voeding/{$measure->id}", $this->fields('management'))->assertSessionHasErrors('category');
            $this->assertModelExists($measure);
            $this->assertSame(0, ManagementAdvies::count());
        }

        // Editing within the category stays possible.
        $this->put("/admin/protocol-measures/voeding/{$snapshotted->id}", $this->fields('voeding', ['title' => 'Nieuwe titel']))->assertSessionHasNoErrors();
        $this->assertSame('Nieuwe titel', $snapshotted->fresh()->title);
    }

    public function test_icons_are_uploaded_replaced_and_removed(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-measures', $this->fields('voeding', ['icon' => UploadedFile::fake()->create('hay.svg', 2, 'image/svg+xml')]))
            ->assertSessionHasNoErrors();
        $measure = VoedingAdvies::sole();
        Storage::disk('public')->assertExists($measure->icon);
        $first = $measure->icon;

        $this->post("/admin/protocol-measures/voeding/{$measure->id}", $this->fields('voeding', ['_method' => 'put', 'icon' => UploadedFile::fake()->image('hay.png')]))
            ->assertSessionHasNoErrors();
        $second = $measure->fresh()->icon;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->put("/admin/protocol-measures/voeding/{$measure->id}", $this->fields('voeding', ['remove_icon' => true]))->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($second);
        $this->assertNull($measure->fresh()->icon);
    }

    public function test_a_failed_update_discards_the_new_icon_and_keeps_the_old_one(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('old.svg', 'icon');
        $measure = VoedingAdvies::create(['title' => 'In gebruik', 'description' => 'x', 'icon' => 'old.svg']);
        $this->snapshot($this->protocol(), 'voeding', $measure);

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/protocol-measures/voeding/{$measure->id}", $this->fields('beweging', ['_method' => 'put', 'icon' => UploadedFile::fake()->image('new.png')]))
            ->assertSessionHasErrors('category');

        $this->assertSame('old.svg', $measure->fresh()->icon);
        Storage::disk('public')->assertExists('old.svg');
        $this->assertSame(['old.svg'], Storage::disk('public')->allFiles());
    }

    public function test_seeders_never_overwrite_or_reinstall_admin_managed_measures(): void
    {
        $this->seed(ManagementAdviesSeeder::class);
        $this->assertSame(range(1, ManagementAdvies::count()), ManagementAdvies::ordered()->pluck('sort_order')->all());
        $edited = ManagementAdvies::where('title', 'Weidebeleid')->sole();
        $edited->update(['description' => 'Eigen tekst']);
        $moved = ManagementAdvies::where('title', 'Hoefverzorging')->sole();
        DB::table('management_adviezen')->where('id', $moved->id)->delete();
        BewegingAdvies::create(['title' => 'HOEFVERZORGING ', 'description' => 'Verplaatst']);
        $count = ManagementAdvies::count();

        $this->seed(ManagementAdviesSeeder::class);

        $this->assertSame($count, ManagementAdvies::count());
        $this->assertSame('Eigen tekst', $edited->fresh()->description);
        $this->assertFalse(ManagementAdvies::whereRaw('LOWER(title) = ?', ['hoefverzorging'])->exists());
    }

    private function adminWithRole(string $role): AdminUser
    {
        return AdminUser::query()->create([
            'name' => ucfirst($role),
            'email' => uniqid("{$role}-").'@example.com',
            'password' => 'password',
            'role' => $role,
            'active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function fields(string $category, array $overrides = []): array
    {
        return [...['category' => $category, 'title' => 'Maatregel', 'description' => 'Omschrijving.', 'layout' => 'normal'], ...$overrides];
    }

    private function protocol(array $attributes = []): Protocol
    {
        $horse = Horse::create(['owner_id' => User::factory()->create()->id, 'name' => 'Boaz', 'status' => 'active']);

        return Protocol::create([...['horse_id' => $horse->id, 'title' => 'Protocol', 'status' => 'paused'], ...$attributes]);
    }

    private function snapshot(Protocol $protocol, string $category, $measure): void
    {
        $config = ProtocolMeasures::config($category);
        DB::table($config['snapshot_table'])->insert([
            'id' => (string) Str::uuid(),
            'protocol_id' => $protocol->id,
            $config['foreign_key'] => $measure->id,
            'title' => $measure->title,
            'description' => $measure->description,
            'layout' => 'normal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
