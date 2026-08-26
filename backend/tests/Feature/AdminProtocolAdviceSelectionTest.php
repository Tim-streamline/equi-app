<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\BewegingAdvies;
use App\Models\Horse;
use App\Models\ManagementAdvies;
use App\Models\Protocol;
use App\Models\ProtocolTemplate;
use App\Models\ProtocolTemplatePhase;
use App\Models\User;
use App\Models\VoedingAdvies;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->actingAs($this->admin, 'admin')
            ->get('/admin/protocols/create?horse_id='.$this->horse->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Protocols/Edit')
                ->has('voedingAdviezen', 2)
                ->where('voedingAdviezen.0.title', 'Onbeperkt ruwvoer')
                ->has('managementAdviezen', 1)
                ->where('managementAdviezen.0.title', 'Vaste routine')
                ->has('bewegingAdviezen', 1)
                ->where('bewegingAdviezen.0.title', 'Dagelijks stappen'));
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
