<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\BewegingAdvies;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\ManagementAdvies;
use App\Models\Protocol;
use App\Models\ProtocolTemplate;
use App\Models\ProtocolTemplatePhase;
use App\Models\User;
use App\Models\VoedingAdvies;
use App\Support\HorseDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminProtocolAdviceSelectionTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Horse $horse;

    private ProtocolTemplate $protocolTemplate;

    private ProtocolTemplatePhase $phaseDefinition;

    private VoedingAdvies $voedingAdvies;

    private VoedingAdvies $extraVoedingAdvies;

    private ManagementAdvies $managementAdvies;

    private BewegingAdvies $bewegingAdvies;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::query()->create([
            'name' => 'Protocol Admin',
            'email' => 'protocol-selection-admin@example.com',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);
        $owner = User::factory()->create();
        $this->horse = Horse::query()->create([
            'owner_id' => $owner->id,
            'name' => 'Boaz',
            'status' => 'active',
        ]);
        IntakeBooking::create(['horse_id' => $this->horse->id, 'user_id' => $owner->id, 'submitted_at' => now()]);
        $this->protocolTemplate = ProtocolTemplate::query()->create(['name' => 'Herstel']);
        $this->phaseDefinition = $this->protocolTemplate->phases()->create([
            'order' => 1,
            'name' => 'Basisfase',
            'required' => true,
        ]);

        $this->voedingAdvies = VoedingAdvies::query()->create([
            'title' => 'Onbeperkt ruwvoer',
            'description' => 'Bied de hele dag passend ruwvoer aan.',
            'layout' => 'roughage',
        ]);
        $this->extraVoedingAdvies = VoedingAdvies::query()->create([
            'title' => 'Voer rustig op',
            'description' => 'Verdeel veranderingen over meerdere dagen.',
        ]);
        $this->managementAdvies = ManagementAdvies::query()->create([
            'title' => 'Vaste routine',
            'description' => 'Houd voer- en rustmomenten voorspelbaar.',
        ]);
        $this->bewegingAdvies = BewegingAdvies::query()->create([
            'title' => 'Dagelijks stappen',
            'description' => 'Bouw rustige beweging geleidelijk op.',
        ]);
    }

    public function test_creator_receives_available_advice_for_all_three_tabs(): void
    {
        $draft = $this->horse->protocols()->sole();
        $this->actingAs($this->admin, 'admin')
            ->get('/admin/protocols/create?horse_id='.$this->horse->id)
            ->assertRedirect('/admin/protocols/'.$draft->id.'/edit');
        $this->get('/admin/protocols/'.$draft->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Protocols/Edit')
                ->where('voedingAdviezen', fn ($rows) => collect($rows)->pluck('id')->all() === VoedingAdvies::orderBy('title')->pluck('id')->all())
                ->where('managementAdviezen', fn ($rows) => collect($rows)->pluck('id')->all() === ManagementAdvies::orderBy('title')->pluck('id')->all())
                ->where('bewegingAdviezen', fn ($rows) => collect($rows)->pluck('id')->all() === BewegingAdvies::orderBy('title')->pluck('id')->all()));
    }

    public function test_selected_advice_is_snapshotted_and_can_be_toggled_on_and_off(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols', $this->payload())
            ->assertSessionHasNoErrors();

        $protocol = Protocol::query()->where('title', 'Boaz herstelprotocol')->firstOrFail();

        $this->assertDatabaseHas('protocol_voeding_adviezen', [
            'protocol_id' => $protocol->id,
            'voeding_advies_id' => $this->voedingAdvies->id,
            'title' => 'Onbeperkt ruwvoer',
            'description' => 'Bied de hele dag passend ruwvoer aan.',
            'layout' => 'roughage',
        ]);
        $this->assertDatabaseHas('protocol_management_adviezen', [
            'protocol_id' => $protocol->id,
            'management_advies_id' => $this->managementAdvies->id,
            'title' => 'Vaste routine',
        ]);
        $this->assertDatabaseHas('protocol_beweging_adviezen', [
            'protocol_id' => $protocol->id,
            'beweging_advies_id' => $this->bewegingAdvies->id,
            'title' => 'Dagelijks stappen',
        ]);

        $this->voedingAdvies->update([
            'title' => 'Gewijzigde instelling',
            'description' => 'Deze wijziging mag de protocolkopie niet aanpassen.',
            'layout' => 'supplementary_feed',
        ]);
        $updatePayload = $this->payload($protocol);
        $updatePayload['voeding_advies_ids'][] = $this->extraVoedingAdvies->id;
        $updatePayload['management_advies_ids'] = [];

        $this->actingAs($this->admin, 'admin')
            ->put('/admin/protocols/'.$protocol->id, $updatePayload)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('protocol_voeding_adviezen', [
            'protocol_id' => $protocol->id,
            'voeding_advies_id' => $this->voedingAdvies->id,
            'title' => 'Onbeperkt ruwvoer',
            'description' => 'Bied de hele dag passend ruwvoer aan.',
            'layout' => 'roughage',
        ]);
        $this->assertDatabaseHas('protocol_voeding_adviezen', [
            'protocol_id' => $protocol->id,
            'voeding_advies_id' => $this->extraVoedingAdvies->id,
            'title' => 'Voer rustig op',
        ]);
        $this->assertDatabaseMissing('protocol_management_adviezen', [
            'protocol_id' => $protocol->id,
        ]);
    }

    public function test_protocol_page_receives_the_selected_advice_snapshots(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols', $this->payload())
            ->assertSessionHasNoErrors();

        $protocol = Protocol::query()->where('title', 'Boaz herstelprotocol')->firstOrFail();

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/protocols/'.$protocol->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Protocols/Show')
                ->has('protocol.voeding_adviezen', 1)
                ->where('protocol.voeding_adviezen.0.title', 'Onbeperkt ruwvoer')
                ->where('protocol.voeding_adviezen.0.description', 'Bied de hele dag passend ruwvoer aan.')
                ->where('protocol.voeding_adviezen.0.layout', 'roughage')
                ->has('protocol.management_adviezen', 1)
                ->where('protocol.management_adviezen.0.title', 'Vaste routine')
                ->has('protocol.beweging_adviezen', 1)
                ->where('protocol.beweging_adviezen.0.title', 'Dagelijks stappen'));
    }

    public function test_advice_id_must_belong_to_its_submitted_category(): void
    {
        $payload = $this->payload();
        $payload['voeding_advies_ids'] = [$this->managementAdvies->id];

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols', $payload)
            ->assertSessionHasErrors('voeding_advies_ids.0');
    }

    public function test_therapist_can_save_customer_nutrition_and_management_overrides(): void
    {
        $payload = $this->payload();
        $payload['customer_settings'] = [
            'target_weight_kg' => 450, 'sugar' => '<6%', 'protein' => '6–8%', 'weekly_update_day' => 5,
            'feed_overrides' => [['id' => hash('sha256', 'feed'), 'status' => 'continue', 'note' => 'Personal decision']],
            'management' => [['id' => $this->managementAdvies->id, 'category' => 'care', 'action' => 'avoid', 'note' => 'For this horse', 'url' => 'https://example.com/advice']],
        ];
        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $payload)->assertSessionHasNoErrors();
        $protocol = Protocol::where('horse_id', $this->horse->id)->firstOrFail();
        $this->assertSame($payload['customer_settings'], $protocol->customer_settings);
        $this->actingAs($this->admin, 'admin')->put('/admin/protocols/'.$protocol->id, $this->payload($protocol))->assertSessionHasNoErrors();
        $this->assertSame($payload['customer_settings'], $protocol->fresh()->customer_settings);
    }

    public function test_overrides_survive_deselection_and_archiving_of_their_library_advice(): void
    {
        $selected = ['id' => $this->voedingAdvies->id, 'instruction' => 'Geselecteerd.'];
        $deselected = ['id' => $this->extraVoedingAdvies->id, 'instruction' => 'Niet geselecteerd, blijft bewaard.'];
        $movement = ['id' => $this->bewegingAdvies->id, 'action' => 'avoid', 'instruction' => 'Niet geselecteerd.'];
        $payload = $this->payload();
        $payload['beweging_advies_ids'] = [];
        $payload['customer_settings'] = ['nutrition_advice' => [$selected, $deselected], 'movement' => [$movement]];

        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $payload)->assertSessionHasNoErrors();
        $protocol = Protocol::where('horse_id', $this->horse->id)->sole();

        foreach (['voeding' => $this->extraVoedingAdvies, 'beweging' => $this->bewegingAdvies] as $category => $advice) {
            $this->post("/admin/protocol-settings/advice/{$category}/{$advice->id}/archive")->assertSessionHas('success');
        }

        $this->put('/admin/protocols/'.$protocol->id, ['beweging_advies_ids' => [], 'customer_settings' => $payload['customer_settings']] + $this->payload($protocol))
            ->assertSessionHasNoErrors();
        $this->assertSame($payload['customer_settings'], $protocol->fresh()->customer_settings);
    }

    public function test_archived_advice_is_only_offered_to_a_protocol_that_already_uses_it(): void
    {
        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $this->payload())->assertSessionHasNoErrors();
        $protocol = Protocol::where('horse_id', $this->horse->id)->sole();
        foreach (['voeding' => [$this->voedingAdvies, $this->extraVoedingAdvies], 'beweging' => [$this->bewegingAdvies]] as $category => $advices) {
            foreach ($advices as $advice) {
                $this->post("/admin/protocol-settings/advice/{$category}/{$advice->id}/archive")->assertSessionHas('success');
            }
        }

        $this->get('/admin/protocols/'.$protocol->id.'/edit')->assertInertia(fn (Assert $page) => $page
            ->where('voedingAdviezen', fn ($rows) => collect($rows)->contains('id', $this->voedingAdvies->id)
                && ! collect($rows)->contains('id', $this->extraVoedingAdvies->id))
            ->where('bewegingAdviezen', fn ($rows) => collect($rows)->contains('id', $this->bewegingAdvies->id)));

        // The customer keeps seeing advice their protocol already uses, and it can still be saved.
        $this->put('/admin/protocols/'.$protocol->id, $this->payload($protocol))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('protocol_beweging_adviezen', ['protocol_id' => $protocol->id, 'beweging_advies_id' => $this->bewegingAdvies->id]);
    }

    public function test_advice_ids_are_stored_in_lowercase(): void
    {
        $payload = $this->payload();
        $payload['beweging_advies_ids'] = [strtoupper($this->bewegingAdvies->id)];
        $payload['customer_settings'] = ['movement' => [['id' => strtoupper($this->bewegingAdvies->id), 'action' => 'avoid', 'instruction' => 'Alleen stappen.']]];

        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $payload)->assertSessionHasNoErrors();
        $protocol = Protocol::where('horse_id', $this->horse->id)->sole();
        $this->assertSame($this->bewegingAdvies->id, $protocol->customer_settings['movement'][0]['id']);
        // Saving the same selection again in another case must not create a second snapshot.
        $this->put('/admin/protocols/'.$protocol->id, ['beweging_advies_ids' => $payload['beweging_advies_ids'], 'customer_settings' => $payload['customer_settings']] + $this->payload($protocol))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $protocol->bewegingAdviezen()->count());

        $movement = app(HorseDashboard::class)->protocol($protocol->fresh(), now()->toImmutable())['movement'];
        $this->assertSame(['Alleen stappen.', 'avoid'], [$movement[0]['description'], $movement[0]['action']]);
    }

    public function test_an_override_for_unknown_or_other_category_advice_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['customer_settings'] = [
            'management' => [['id' => $this->bewegingAdvies->id, 'category' => 'care', 'action' => 'do']],
            'movement' => [['id' => (string) Str::uuid(), 'action' => 'do']],
        ];

        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $payload)
            ->assertSessionHasErrors(['customer_settings.management.0.id', 'customer_settings.movement.0.id']);
    }

    public function test_the_cleanup_migration_removes_overrides_left_by_earlier_deletions(): void
    {
        $kept = ['id' => strtoupper($this->managementAdvies->id), 'category' => 'care', 'action' => 'do'];
        $orphan = ['id' => (string) Str::uuid(), 'category' => 'care', 'action' => 'do'];
        $protocol = $this->horse->protocols()->sole();
        $protocol->update(['customer_settings' => ['weight_kg' => 500, 'management' => [$orphan, $kept]]]);

        (require database_path('migrations/2026_11_11_000002_remove_overrides_for_deleted_advice.php'))->up();

        $this->assertSame(['weight_kg' => 500, 'management' => [['id' => $this->managementAdvies->id] + $kept]], $protocol->fresh()->customer_settings);
    }

    public function test_invalid_customer_settings_cannot_be_published(): void
    {
        $payload = $this->payload();
        $payload['customer_settings'] = ['target_weight_kg' => -10, 'weekly_update_day' => 8,
            'management' => [['id' => $this->managementAdvies->id, 'category' => 'care', 'action' => 'do', 'url' => 'javascript:alert(1)']]];
        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $payload)
            ->assertSessionHasErrors(['customer_settings.target_weight_kg', 'customer_settings.weekly_update_day', 'customer_settings.management.0.url']);
    }

    /** @return array<string, mixed> */
    private function payload(?Protocol $protocol = null): array
    {
        $phases = $protocol?->phases()->with('weeks')->get()->map(fn ($phase): array => [
            'id' => $phase->id,
            'client_key' => $phase->id,
            'protocol_template_phase_id' => $phase->protocol_template_phase_id,
            'week_count' => $phase->weeks->count(),
            'supplements' => [],
        ])->all() ?? [];

        return [
            'horse_id' => $this->horse->id,
            'protocol_template_id' => $this->protocolTemplate->id,
            'therapist_id' => null,
            'title' => 'Boaz herstelprotocol',
            'started_at' => null,
            'status' => 'paused',
            'published' => false,
            'analysis' => ['cause' => null],
            'advice' => [],
            'voeding_advies_ids' => [$this->voedingAdvies->id],
            'management_advies_ids' => [$this->managementAdvies->id],
            'beweging_advies_ids' => [$this->bewegingAdvies->id],
            'phases' => $phases,
        ];
    }
}
