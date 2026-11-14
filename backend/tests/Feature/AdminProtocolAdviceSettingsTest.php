<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\BewegingAdvies;
use App\Models\ManagementAdvies;
use App\Models\VoedingAdvies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminProtocolAdviceSettingsTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::query()->create([
            'name' => 'Protocol Settings Admin',
            'email' => 'protocol-advice-settings@example.com',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);
    }

    public function test_admin_can_open_protocol_settings_with_the_three_advice_tabs(): void
    {
        foreach ([VoedingAdvies::class, ManagementAdvies::class, BewegingAdvies::class] as $model) {
            foreach (['Zzz test', 'Aaa test'] as $title) {
                $model::create(['title' => $title, 'description' => 'Test advice']);
            }
        }
        $this->actingAs($this->admin, 'admin')
            ->get('/admin/protocol-settings/advice')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProtocolAdviceSettings/Index')
                ->where('voedingAdviezen', fn ($rows) => collect($rows)->pluck('id')->all() === VoedingAdvies::orderBy('title')->pluck('id')->all())
                ->where('managementAdviezen', fn ($rows) => collect($rows)->pluck('id')->all() === ManagementAdvies::orderBy('title')->pluck('id')->all())
                ->where('bewegingAdviezen', fn ($rows) => collect($rows)->pluck('id')->all() === BewegingAdvies::orderBy('title')->pluck('id')->all()));
    }

    public function test_advice_is_archived_and_restored_instead_of_deleted(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('advice.svg', 'icon');
        $advice = ManagementAdvies::create(['title' => 'Archiveren', 'description' => 'Test', 'icon' => 'advice.svg']);
        $url = '/admin/protocol-settings/advice/management/'.$advice->id;

        $this->actingAs($this->admin, 'admin')->post($url.'/archive')->assertSessionHas('success');
        $this->assertNotNull($advice->fresh()->archived_at);
        // The row and icon stay: protocols that use the advice still show them.
        Storage::disk('public')->assertExists('advice.svg');
        $this->assertDatabaseHas('audit_logs', ['target_id' => $advice->id, 'action' => 'archived']);
        $this->get('/admin/protocol-settings/advice')->assertInertia(fn (Assert $page) => $page
            ->where('managementAdviezen', fn ($rows) => collect($rows)->firstWhere('id', $advice->id)['archived_at'] !== null));

        $this->post($url.'/restore')->assertSessionHas('success');
        $this->assertNull($advice->fresh()->archived_at);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $advice->id, 'action' => 'restored']);
        $this->delete($url)->assertStatus(405);
    }

    public function test_admin_can_manage_each_protocol_advice_entity(): void
    {
        foreach ([
            'voeding' => 'voeding_adviezen',
            'management' => 'management_adviezen',
            'beweging' => 'beweging_adviezen',
        ] as $category => $table) {
            $this->actingAs($this->admin, 'admin')
                ->post("/admin/protocol-settings/advice/{$category}", [
                    'title' => ucfirst($category).' advies',
                    'description' => "Beschrijving voor {$category}.",
                    'layout' => $category === 'voeding' ? 'roughage' : 'normal',
                ])
                ->assertSessionHasNoErrors();

            $this->assertDatabaseHas($table, [
                'title' => ucfirst($category).' advies',
                'description' => "Beschrijving voor {$category}.",
                'layout' => $category === 'voeding' ? 'roughage' : 'normal',
            ]);
        }

        $managementId = (string) DB::table('management_adviezen')->where('title', 'Management advies')->value('id');

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/protocol-settings/advice/management/{$managementId}", [
                'title' => 'Aangepast managementadvies',
                'description' => 'Een bijgewerkte beschrijving.',
                'layout' => 'normal',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('management_adviezen', [
            'id' => $managementId,
            'title' => 'Aangepast managementadvies',
        ]);

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/protocol-settings/advice/management/{$managementId}/archive")
            ->assertSessionHasNoErrors();

        $this->assertNotNull(ManagementAdvies::findOrFail($managementId)->archived_at);
    }

    public function test_title_and_description_are_required(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-settings/advice/voeding', [
                'title' => '',
                'description' => '',
            ])
            ->assertSessionHasErrors(['title', 'description']);
    }

    public function test_only_voeding_accepts_the_special_layouts(): void
    {
        foreach (['normal', 'link_to_library', 'roughage', 'supplementary_feed'] as $layout) {
            $this->actingAs($this->admin, 'admin')
                ->post('/admin/protocol-settings/advice/voeding', [
                    'title' => "Voeding {$layout}",
                    'description' => 'Layoutcontrole.',
                    'layout' => $layout,
                ])
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-settings/advice/management', [
                'title' => 'Onjuiste layout',
                'description' => 'Niet toegestaan voor management.',
                'layout' => 'roughage',
            ])
            ->assertSessionHasErrors('layout');
    }

    public function test_admin_can_upload_replace_and_remove_an_advice_icon(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-settings/advice/voeding', [
                'title' => 'Ruwvoer met icoon',
                'description' => 'Een visueel herkenbaar advies.',
                'layout' => 'roughage',
                'icon' => UploadedFile::fake()->create('hay.svg', 2, 'image/svg+xml'),
            ])
            ->assertSessionHasNoErrors();

        $advice = DB::table('voeding_adviezen')->where('title', 'Ruwvoer met icoon')->first();
        $this->assertNotNull($advice->icon);
        Storage::disk('public')->assertExists($advice->icon);
        $firstIcon = $advice->icon;

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/protocol-settings/advice/voeding/{$advice->id}", [
                '_method' => 'put',
                'title' => 'Ruwvoer met icoon',
                'description' => 'Een visueel herkenbaar advies.',
                'layout' => 'roughage',
                'icon' => UploadedFile::fake()->image('hay.png'),
            ])
            ->assertSessionHasNoErrors();

        $advice = DB::table('voeding_adviezen')->find($advice->id);
        $this->assertNotSame($firstIcon, $advice->icon);
        Storage::disk('public')->assertMissing($firstIcon);
        Storage::disk('public')->assertExists($advice->icon);

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/protocol-settings/advice/voeding/{$advice->id}", [
                'title' => 'Ruwvoer met icoon',
                'description' => 'Een visueel herkenbaar advies.',
                'layout' => 'roughage',
                'remove_icon' => true,
            ])
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($advice->icon);
        $this->assertDatabaseHas('voeding_adviezen', ['id' => $advice->id, 'icon' => null]);
    }

    public function test_non_admin_cannot_manage_protocol_advice_settings(): void
    {
        $supportUser = AdminUser::query()->create([
            'name' => 'Support User',
            'email' => 'protocol-advice-support@example.com',
            'password' => 'password',
            'role' => 'support',
            'active' => true,
        ]);

        $this->actingAs($supportUser, 'admin')
            ->get('/admin/protocol-settings/advice')
            ->assertForbidden();
    }
}
