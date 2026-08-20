<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\Protocol;
use App\Models\ProtocolType;
use App\Models\ProtocolTypePhase;
use App\Models\Supplement;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminProtocolManagementTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Horse $horse;

    private Therapist $therapist;

    private ProtocolType $protocolType;

    /** @var array<int, ProtocolTypePhase> */
    private array $phaseDefinitions;

    private Supplement $defaultSupplement;

    private Supplement $optionalSupplement;

    private Supplement $secondPhaseSupplement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::query()->create([
            'name' => 'Protocol Admin',
            'email' => 'protocol-admin@example.com',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);
        $owner = User::factory()->create(['name' => 'Horse Owner']);
        $this->horse = Horse::query()->create([
            'owner_id' => $owner->id,
            'name' => 'Boaz',
            'breed' => 'Fries Tinker mix',
            'age' => 10,
            'sex' => 'ruin',
            'weight_kg' => 550,
            'status' => 'active',
        ]);
        $this->therapist = Therapist::query()->create([
            'name' => 'Shelley',
            'title' => 'Equine therapist',
        ]);
        $this->protocolType = ProtocolType::query()->create([
            'name' => 'Darm protocol',
        ]);
        $this->phaseDefinitions = collect(range(1, 3))
            ->map(fn (int $phaseNumber) => $this->protocolType->phases()->create([
                'order' => $phaseNumber,
                'name' => "Configured phase {$phaseNumber}",
                'description' => "Description {$phaseNumber}",
                'required' => $phaseNumber === 1,
            ]))
            ->all();

        $this->defaultSupplement = $this->phaseDefinitions[0]->supplements()->create([
            'name' => 'Psylliumzaad',
            'description' => 'Ondersteunt de darmgezondheid.',
            'supplement_type' => 'kruid',
            'add_by_default' => true,
        ]);
        $this->optionalSupplement = $this->phaseDefinitions[0]->supplements()->create([
            'name' => 'Zink',
            'description' => 'Ondersteunt huid en vacht.',
            'supplement_type' => 'mineraal',
            'add_by_default' => false,
        ]);
        $this->secondPhaseSupplement = $this->phaseDefinitions[1]->supplements()->create([
            'name' => 'Kamille',
            'supplement_type' => 'kruid',
            'add_by_default' => false,
        ]);
    }

    public function test_admin_can_open_the_horse_specific_protocol_creator(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->get('/admin/protocols/create?horse_id='.$this->horse->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Protocols/Edit')
                ->where('protocol', null)
                ->where('selectedHorseId', $this->horse->id)
                ->where('protocolTypes.0.name', 'Darm protocol')
                ->where('protocolTypes.0.phases.0.required', true)
                ->where('protocolTypes.0.phases.0.supplements.0.name', 'Psylliumzaad')
                ->has('horses', 1)
                ->where('horses.0.name', 'Boaz'));
    }

    public function test_admin_can_create_a_complete_protocol(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols', $this->payload());

        $protocol = Protocol::query()->where('title', 'Boaz recovery protocol')->firstOrFail();

        $response->assertRedirect(route('admin.protocols.edit', $protocol));
        $this->assertSame($this->horse->id, $protocol->horse_id);
        $this->assertSame($this->protocolType->id, $protocol->protocol_type_id);
        $this->assertSame($this->therapist->id, $protocol->therapist_id);
        $this->assertSame(3, $protocol->phases()->count());
        $this->assertSame(2, $protocol->phases()->first()->items()->count());
        $this->assertSame(2, $protocol->phases()->withCount('supplements')->get()->sum('supplements_count'));
        $this->assertSame(2, $protocol->tasks()->count());
        $this->assertSame('Restore the gut first.', $protocol->analysis()->firstOrFail()->cause);
        $this->assertSame(2, $protocol->analysis()->firstOrFail()->advice()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'target_type' => 'Protocol',
            'target_id' => $protocol->id,
        ]);
    }

    public function test_protocol_list_shows_and_searches_protocol_type_and_includes_the_current_phase(): void
    {
        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $this->payload());

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/protocols?q=Darm')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Protocols/Index')
                ->where('protocols.data.0.protocol_type.name', 'Darm protocol')
                ->where('protocols.data.0.current_phase.title', 'Phase 1 — Gut recovery')
                ->missing('protocols.data.0.tasks_count'));
    }

    public function test_admin_can_edit_nested_protocol_content(): void
    {
        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $this->payload());
        $protocol = Protocol::query()->where('title', 'Boaz recovery protocol')->firstOrFail();
        $protocol->load('phases.items', 'tasks', 'analysis.advice');

        $firstPhase = $protocol->phases[0];
        $secondPhase = $protocol->phases[1];
        $thirdPhase = $protocol->phases[2];
        $keptItem = $firstPhase->items[0];
        $keptTask = $protocol->tasks[0];
        $keptAdvice = $protocol->analysis->advice[0];

        $payload = $this->payload();
        $payload['title'] = 'Boaz updated recovery protocol';
        $payload['analysis']['cause'] = 'Updated clinical conclusion.';
        foreach ($protocol->phases as $index => $phase) {
            $payload['phases'][$index]['id'] = $phase->id;
            $payload['phases'][$index]['client_key'] = $phase->id;
        }
        $payload['phases'][0] = [
            'id' => $firstPhase->id,
            'client_key' => $firstPhase->id,
            'protocol_type_phase_id' => $this->phaseDefinitions[0]->id,
            'title' => 'Updated phase 1',
            'state' => 'active',
            'week_start' => 1,
            'week_end' => 4,
            'chip_label' => 'Active · wk 1–4',
            'items' => [[
                'id' => $keptItem->id,
                'label' => 'Updated roughage plan',
            ]],
            'supplement_ids' => [$this->optionalSupplement->id],
        ];
        $payload['tasks'] = [[
            'id' => $keptTask->id,
            'phase_key' => $firstPhase->id,
            'label' => 'Updated linseed task',
            'meta' => '2 tbsp',
            'kind' => 'feeding',
            'active_from' => '2026-08-17',
            'active_until' => null,
            'reference_item_id' => null,
        ]];
        $payload['advice'] = [[
            'id' => $keptAdvice->id,
            'icon_key' => 'leaf',
            'title' => 'Updated nutrition',
            'body' => 'Use the revised feed plan.',
        ]];

        $response = $this->actingAs($this->admin, 'admin')
            ->put('/admin/protocols/'.$protocol->id, $payload);

        $response->assertRedirect(route('admin.protocols.edit', $protocol));
        $this->assertDatabaseHas('protocols', ['id' => $protocol->id, 'title' => 'Boaz updated recovery protocol']);
        $this->assertDatabaseHas('protocol_phases', ['id' => $firstPhase->id, 'title' => 'Updated phase 1']);
        $this->assertDatabaseHas('protocol_phases', ['id' => $secondPhase->id]);
        $this->assertDatabaseHas('protocol_phases', ['id' => $thirdPhase->id]);
        $this->assertSame(3, $protocol->phases()->count());
        $this->assertDatabaseHas('protocol_phase_items', ['id' => $keptItem->id, 'label' => 'Updated roughage plan']);
        $this->assertDatabaseHas('protocol_phase_supplements', [
            'protocol_phase_id' => $firstPhase->id,
            'supplement_id' => $this->optionalSupplement->id,
        ]);
        $this->assertDatabaseMissing('protocol_phase_supplements', [
            'protocol_phase_id' => $firstPhase->id,
            'supplement_id' => $this->defaultSupplement->id,
        ]);
        $this->assertSame(1, $protocol->tasks()->count());
        $this->assertDatabaseHas('protocol_tasks', ['id' => $keptTask->id, 'label' => 'Updated linseed task']);
        $this->assertSame(1, $protocol->analysis()->firstOrFail()->advice()->count());
        $this->assertDatabaseHas('protocol_advice', ['id' => $keptAdvice->id, 'title' => 'Updated nutrition']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'updated',
            'target_type' => 'Protocol',
            'target_id' => $protocol->id,
        ]);
    }

    public function test_required_phases_are_added_automatically_and_cannot_be_removed(): void
    {
        $createPayload = $this->payload();
        $createPayload['phases'] = [$createPayload['phases'][1]];

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols', $createPayload)
            ->assertSessionHasNoErrors();

        $protocol = Protocol::query()->where('title', 'Boaz recovery protocol')->firstOrFail();
        $requiredProtocolPhase = $protocol->phases()
            ->where('protocol_type_phase_id', $this->phaseDefinitions[0]->id)
            ->firstOrFail();
        $optionalProtocolPhase = $protocol->phases()
            ->where('protocol_type_phase_id', $this->phaseDefinitions[1]->id)
            ->firstOrFail();
        $this->assertSame(2, $protocol->phases()->count());

        $withoutRequired = $this->payload();
        $withoutRequired['phases'] = [$withoutRequired['phases'][1]];
        $withoutRequired['phases'][0]['id'] = $optionalProtocolPhase->id;

        $this->actingAs($this->admin, 'admin')
            ->put('/admin/protocols/'.$protocol->id, $withoutRequired)
            ->assertSessionHasErrors('phases');

        $withoutOptional = $this->payload();
        $withoutOptional['phases'] = [$withoutOptional['phases'][0]];
        $withoutOptional['phases'][0]['id'] = $requiredProtocolPhase->id;

        $this->actingAs($this->admin, 'admin')
            ->put('/admin/protocols/'.$protocol->id, $withoutOptional)
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $protocol->phases()->count());
        $this->assertDatabaseHas('protocol_phases', [
            'id' => $requiredProtocolPhase->id,
            'protocol_type_phase_id' => $this->phaseDefinitions[0]->id,
        ]);
        $this->assertDatabaseHas('protocol_phase_supplements', [
            'protocol_phase_id' => $requiredProtocolPhase->id,
            'supplement_id' => $this->defaultSupplement->id,
        ]);
    }

    public function test_supplements_must_belong_to_the_selected_phase(): void
    {
        $payload = $this->payload();
        $payload['phases'][0]['supplement_ids'] = [$this->secondPhaseSupplement->id];

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols', $payload)
            ->assertSessionHasErrors('phases.0.supplement_ids.0');

        $this->assertDatabaseMissing('protocols', ['title' => 'Boaz recovery protocol']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'horse_id' => $this->horse->id,
            'protocol_type_id' => $this->protocolType->id,
            'therapist_id' => $this->therapist->id,
            'title' => 'Boaz recovery protocol',
            'subtitle_analyse' => 'Fries Tinker mix · gut health',
            'subtitle_protocol' => 'Week 1 of 8 · Phase 1 active',
            'subtitle_calendar' => 'August 2026',
            'total_weeks' => 8,
            'current_week' => 1,
            'started_at' => '2026-08-17',
            'status' => 'paused',
            'analysis' => ['cause' => 'Restore the gut first.'],
            'advice' => [
                ['id' => null, 'icon_key' => 'leaf', 'title' => 'Nutrition', 'body' => 'Adjust roughage and supplements.'],
                ['id' => null, 'icon_key' => 'run', 'title' => 'Movement', 'body' => 'Build movement gradually.'],
            ],
            'phases' => [
                [
                    'id' => null,
                    'client_key' => 'phase-one',
                    'protocol_type_phase_id' => $this->phaseDefinitions[0]->id,
                    'title' => 'Phase 1 — Gut recovery',
                    'state' => 'active',
                    'week_start' => 1,
                    'week_end' => 4,
                    'chip_label' => 'Active · wk 1–4',
                    'items' => [
                        ['id' => null, 'label' => 'Roughage plan'],
                        ['id' => null, 'label' => 'Observe manure'],
                    ],
                    'supplement_ids' => [$this->defaultSupplement->id],
                ],
                [
                    'id' => null,
                    'client_key' => 'phase-two',
                    'protocol_type_phase_id' => $this->phaseDefinitions[1]->id,
                    'title' => 'Phase 2 — Rebuild',
                    'state' => 'upcoming',
                    'week_start' => 5,
                    'week_end' => 6,
                    'chip_label' => 'From week 5',
                    'items' => [],
                    'supplement_ids' => [$this->secondPhaseSupplement->id],
                ],
                [
                    'id' => null,
                    'client_key' => 'phase-three',
                    'protocol_type_phase_id' => $this->phaseDefinitions[2]->id,
                    'title' => 'Phase 3 — Stabilize',
                    'state' => 'upcoming',
                    'week_start' => 7,
                    'week_end' => 8,
                    'chip_label' => 'From week 7',
                    'items' => [],
                    'supplement_ids' => [],
                ],
            ],
            'tasks' => [
                [
                    'id' => null,
                    'phase_key' => 'phase-one',
                    'label' => 'Linseed through roughage',
                    'meta' => '1 tbsp',
                    'kind' => 'feeding',
                    'active_from' => '2026-08-17',
                    'active_until' => null,
                    'reference_item_id' => null,
                ],
                [
                    'id' => null,
                    'phase_key' => 'phase-one',
                    'label' => 'Observe manure',
                    'meta' => null,
                    'kind' => 'observation',
                    'active_from' => null,
                    'active_until' => null,
                    'reference_item_id' => null,
                ],
            ],
        ];
    }
}
