<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\BewegingAdvies;
use App\Models\Horse;
use App\Models\ManagementAdvies;
use App\Models\Protocol;
use App\Models\ProtocolTemplate;
use App\Models\ProtocolTemplatePhase;
use App\Models\Supplement;
use App\Models\SupplementWeek;
use App\Models\Therapist;
use App\Models\User;
use App\Models\VoedingAdvies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminProtocolManagementTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Horse $horse;

    private Therapist $therapist;

    private ProtocolTemplate $protocolTemplate;

    /** @var array<int, ProtocolTemplatePhase> */
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
        $this->protocolTemplate = ProtocolTemplate::query()->create([
            'name' => 'Darm protocol',
        ]);
        $this->phaseDefinitions = collect(range(1, 3))
            ->map(fn (int $phaseNumber) => $this->protocolTemplate->phases()->create([
                'order' => $phaseNumber,
                'name' => "Configured phase {$phaseNumber}",
                'description' => "Description {$phaseNumber}",
                'required' => $phaseNumber === 1,
            ]))
            ->all();

        foreach ([4, 2, 2] as $phaseIndex => $weekCount) {
            foreach (range(1, $weekCount) as $number) {
                $this->phaseDefinitions[$phaseIndex]->weeks()->create(['number' => $number]);
            }
        }

        $this->defaultSupplement = $this->phaseDefinitions[0]->supplements()->create([
            'name' => 'Psylliumzaad',
            'description' => 'Ondersteunt de darmgezondheid.',
            'supplement_type' => 'kruid',
            'dosis_type' => 'per_kg',
            'dosis' => 0.32,
            'unit' => 'g',
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

        foreach ($this->phaseDefinitions[0]->weeks as $week) {
            SupplementWeek::query()->create([
                'supplement_id' => $this->defaultSupplement->id,
                'protocol_template_phase_week_id' => $week->id,
            ]);
        }
        foreach ($this->phaseDefinitions[1]->weeks as $week) {
            SupplementWeek::query()->create([
                'supplement_id' => $this->secondPhaseSupplement->id,
                'protocol_template_phase_week_id' => $week->id,
            ]);
        }
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
                ->where('protocolTemplates.0.name', 'Darm protocol')
                ->where('protocolTemplates.0.phases.0.required', true)
                ->where('protocolTemplates.0.phases.0.supplements.0.name', 'Psylliumzaad')
                ->where('protocolTemplates.0.phases.0.supplements.0.dosis_type', 'per_kg')
                ->where('protocolTemplates.0.phases.0.supplements.0.dosis', 0.32)
                ->where('protocolTemplates.0.phases.0.supplements.0.unit', 'g')
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
        $this->assertSame($this->protocolTemplate->id, $protocol->protocol_template_id);
        $this->assertSame($this->therapist->id, $protocol->therapist_id);
        $this->assertSame(3, $protocol->phases()->count());
        $this->assertSame(8, $protocol->phases()->withCount('weeks')->get()->sum('weeks_count'));
        $this->assertSame('Darm protocol', $protocol->protocol_template_name);
        $this->assertNull($protocol->published_at);
        $this->assertSame(2, $protocol->phases()->withCount('supplements')->get()->sum('supplements_count'));
        $this->assertDatabaseHas('protocol_phase_supplements', [
            'supplement_id' => $this->defaultSupplement->id,
            'name' => 'Psylliumzaad',
            'aantal_per_week' => 4,
        ]);
        $this->assertDatabaseCount('protocol_phase_supplement_weeks', 6);
        $this->assertSame('Restore the gut first.', $protocol->analysis()->firstOrFail()->cause);
        $this->assertSame(2, $protocol->analysis()->firstOrFail()->advice()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'target_type' => 'Protocol',
            'target_id' => $protocol->id,
        ]);
    }

    public function test_rust_period_does_not_restrict_protocol_week_selection(): void
    {
        $this->defaultSupplement->update(['rust_periode_in_weken' => 2]);
        $payload = $this->payload();
        $payload['phases'][0]['supplements'][0]['week_numbers'] = [1, 3];

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols', $payload)
            ->assertSessionHasNoErrors();

        $protocol = Protocol::query()->where('title', 'Boaz recovery protocol')->firstOrFail();
        $selection = $protocol->phases()->firstOrFail()->supplements()->firstOrFail();

        $this->assertSame(
            [1, 3],
            $selection->weeks()
                ->with('protocolPhaseWeek')
                ->get()
                ->pluck('protocolPhaseWeek.number')
                ->sort()
                ->values()
                ->all(),
        );
    }

    public function test_template_version_storage_and_refresh_endpoint_are_removed(): void
    {
        $this->assertFalse(Schema::hasColumn('protocol_templates', 'version'));
        $this->assertFalse(Schema::hasColumn('protocols', 'template_version'));
        $this->assertFalse(Schema::hasColumn('protocols', 'template_snapshot'));

        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $this->payload());
        $protocol = Protocol::query()->where('title', 'Boaz recovery protocol')->firstOrFail();

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols/'.$protocol->id.'/refresh-template')
            ->assertNotFound();
    }

    public function test_protocol_list_shows_and_searches_protocol_template_and_includes_the_current_phase(): void
    {
        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $this->payload());

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/protocols?q=Darm')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Protocols/Index')
                ->where('protocols.data.0.protocol_template_name', 'Darm protocol')
                ->where('protocols.data.0.protocol_template.name', 'Darm protocol')
                ->where('protocols.data.0.current_phase.title', 'Configured phase 1'));
    }

    public function test_protocol_can_contain_more_than_three_template_phases(): void
    {
        $fourthPhase = $this->protocolTemplate->phases()->create([
            'order' => 4,
            'name' => 'Configured phase 4',
            'description' => 'Description 4',
            'required' => false,
        ]);
        $fourthPhase->weeks()->create(['number' => 1]);

        $payload = $this->payload();
        array_unshift($payload['phases'], [
            'id' => null,
            'client_key' => 'phase-four',
            'protocol_template_phase_id' => $fourthPhase->id,
            'title' => 'This submitted title is ignored',
            'week_count' => 1,
            'supplements' => [],
        ]);

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols', $payload)
            ->assertSessionHasNoErrors();

        $protocol = Protocol::query()->where('title', 'Boaz recovery protocol')->firstOrFail();

        $this->assertSame(4, $protocol->phases()->count());
        $this->assertDatabaseHas('protocol_phases', [
            'protocol_id' => $protocol->id,
            'protocol_template_phase_id' => $fourthPhase->id,
            'title' => 'Configured phase 4',
            'order' => 3,
        ]);
    }

    public function test_phase_can_start_after_completed_weeks_of_the_previous_phase(): void
    {
        $this->phaseDefinitions[1]->update(['start_after_previous_phase_weeks' => 2]);
        $payload = $this->payload();
        unset($payload['phases'][1]['start_after_previous_phase_weeks']);

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols', $payload)
            ->assertSessionHasNoErrors();

        $protocol = Protocol::query()->where('title', 'Boaz recovery protocol')->firstOrFail();
        $phases = $protocol->phases()->with('weeks')->get();

        $this->assertNull($phases[0]->start_after_previous_phase_weeks);
        $this->assertSame(2, $phases[1]->start_after_previous_phase_weeks);
        $this->assertSame([3, 4], $phases[1]->weeks->pluck('protocol_week_number')->all());
        $this->assertSame([5, 6], $phases[2]->weeks->pluck('protocol_week_number')->all());
        $this->assertSame(6, $protocol->refresh()->total_weeks);

        $updatePayload = $this->payload();
        foreach ($phases as $index => $phase) {
            $updatePayload['phases'][$index]['id'] = $phase->id;
            $updatePayload['phases'][$index]['client_key'] = $phase->id;
            $updatePayload['phases'][$index]['start_after_previous_phase_weeks'] = $index === 1 ? 1 : null;

            foreach ($updatePayload['phases'][$index]['supplements'] as $supplementIndex => $submittedSupplement) {
                $updatePayload['phases'][$index]['supplements'][$supplementIndex]['id'] = $phase->supplements()
                    ->where('supplement_id', $submittedSupplement['supplement_id'])
                    ->value('id');
            }
        }

        $this->actingAs($this->admin, 'admin')
            ->put('/admin/protocols/'.$protocol->id, $updatePayload)
            ->assertSessionHasNoErrors();

        $secondPhase = $protocol->phases()->where('order', 1)->with('weeks')->firstOrFail();
        $thirdPhase = $protocol->phases()->where('order', 2)->with('weeks')->firstOrFail();
        $this->assertSame(1, $secondPhase->start_after_previous_phase_weeks);
        $this->assertSame([2, 3], $secondPhase->weeks->pluck('protocol_week_number')->all());
        $this->assertSame([4, 5], $thirdPhase->weeks->pluck('protocol_week_number')->all());
        $this->assertSame(5, $protocol->refresh()->total_weeks);
    }

    public function test_phase_with_zero_start_delay_starts_together_with_previous_phase(): void
    {
        $this->phaseDefinitions[1]->update(['start_after_previous_phase_weeks' => 0]);
        $payload = $this->payload();
        unset($payload['phases'][1]['start_after_previous_phase_weeks']);

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols', $payload)
            ->assertSessionHasNoErrors();

        $protocol = Protocol::query()->where('title', 'Boaz recovery protocol')->firstOrFail();
        $phases = $protocol->phases()->with('weeks')->get();

        $this->assertSame(0, $phases[1]->start_after_previous_phase_weeks);
        $this->assertSame([1, 2, 3, 4], $phases[0]->weeks->pluck('protocol_week_number')->all());
        $this->assertSame([1, 2], $phases[1]->weeks->pluck('protocol_week_number')->all());
        $this->assertSame([3, 4], $phases[2]->weeks->pluck('protocol_week_number')->all());
        $this->assertSame(4, $protocol->refresh()->total_weeks);
    }

    public function test_admin_can_edit_nested_protocol_content(): void
    {
        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $this->payload());
        $protocol = Protocol::query()->where('title', 'Boaz recovery protocol')->firstOrFail();
        $protocol->load('phases.supplements', 'analysis.advice');

        $firstPhase = $protocol->phases[0];
        $secondPhase = $protocol->phases[1];
        $thirdPhase = $protocol->phases[2];
        $keptAdvice = $protocol->analysis->advice[0];

        $payload = $this->payload();
        $payload['title'] = 'Boaz updated recovery protocol';
        $payload['analysis']['cause'] = 'Updated clinical conclusion.';
        foreach ($protocol->phases as $index => $phase) {
            $payload['phases'][$index]['id'] = $phase->id;
            $payload['phases'][$index]['client_key'] = $phase->id;
            foreach ($payload['phases'][$index]['supplements'] as $supplementIndex => $supplement) {
                $payload['phases'][$index]['supplements'][$supplementIndex]['id'] = $phase->supplements
                    ->firstWhere('supplement_id', $supplement['supplement_id'])?->id;
            }
        }
        $payload['phases'][0] = [
            'id' => $firstPhase->id,
            'client_key' => $firstPhase->id,
            'protocol_template_phase_id' => $this->phaseDefinitions[0]->id,
            'title' => 'Updated phase 1',
            'week_count' => 5,
            'supplements' => [[
                'id' => null,
                'supplement_id' => $this->optionalSupplement->id,
                'dosage' => '40 g',
                'aantal_per_week' => 4,
                'instructions' => 'Door het voer.',
                'week_numbers' => [1, 4],
            ]],
        ];
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
        $this->assertDatabaseHas('protocol_phases', ['id' => $firstPhase->id, 'title' => 'Configured phase 1']);
        $this->assertDatabaseHas('protocol_phases', ['id' => $secondPhase->id]);
        $this->assertDatabaseHas('protocol_phases', ['id' => $thirdPhase->id]);
        $this->assertSame(3, $protocol->phases()->count());
        $this->assertDatabaseHas('protocol_phase_supplements', [
            'protocol_phase_id' => $firstPhase->id,
            'supplement_id' => $this->optionalSupplement->id,
            'dosage' => '40 g',
        ]);
        $this->assertSame(5, $firstPhase->weeks()->count());
        $this->assertSame([1, 4], $firstPhase->supplements()->firstOrFail()->weeks()
            ->join('protocol_phase_weeks', 'protocol_phase_weeks.id', '=', 'protocol_phase_supplement_weeks.protocol_phase_week_id')
            ->orderBy('protocol_phase_weeks.number')
            ->pluck('protocol_phase_weeks.number')
            ->all());
        $this->assertDatabaseMissing('protocol_phase_supplements', [
            'protocol_phase_id' => $firstPhase->id,
            'supplement_id' => $this->defaultSupplement->id,
        ]);
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
        $fixedDoseSupplement = $this->phaseDefinitions[0]->supplements()->create([
            'name' => 'Vloeibaar supplement',
            'supplement_type' => 'supplement',
            'instructions' => 'Goed door het voer mengen.',
            'dosis_type' => 'vast',
            'dosis' => 25,
            'unit' => 'ml',
            'add_by_default' => true,
        ]);
        $createPayload = $this->payload();
        $createPayload['phases'] = [$createPayload['phases'][1]];

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols', $createPayload)
            ->assertSessionHasNoErrors();

        $protocol = Protocol::query()->where('title', 'Boaz recovery protocol')->firstOrFail();
        $requiredProtocolPhase = $protocol->phases()
            ->where('protocol_template_phase_id', $this->phaseDefinitions[0]->id)
            ->firstOrFail();
        $optionalProtocolPhase = $protocol->phases()
            ->where('protocol_template_phase_id', $this->phaseDefinitions[1]->id)
            ->firstOrFail();
        $this->assertSame(2, $protocol->phases()->count());
        $this->assertDatabaseHas('protocol_phase_supplements', [
            'protocol_phase_id' => $requiredProtocolPhase->id,
            'supplement_id' => $this->defaultSupplement->id,
            'dosage' => '176 g',
        ]);
        $this->assertDatabaseHas('protocol_phase_supplements', [
            'protocol_phase_id' => $requiredProtocolPhase->id,
            'supplement_id' => $fixedDoseSupplement->id,
            'dosage' => '25 ml',
            'instructions' => 'Goed door het voer mengen.',
        ]);

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
            'protocol_template_phase_id' => $this->phaseDefinitions[0]->id,
        ]);
        $this->assertDatabaseHas('protocol_phase_supplements', [
            'protocol_phase_id' => $requiredProtocolPhase->id,
            'supplement_id' => $this->defaultSupplement->id,
        ]);
    }

    public function test_per_600_kg_template_dosage_is_calculated_for_the_horse(): void
    {
        $supplement = $this->phaseDefinitions[0]->supplements()->create([
            'name' => 'Herbal mix',
            'supplement_type' => 'kruid',
            'dosis_type' => 'per_600_kg',
            'dosis' => 3,
            'unit' => 'eetlepel',
            'add_by_default' => true,
        ]);
        $payload = $this->payload();
        $payload['phases'] = [$payload['phases'][1]];

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols', $payload)
            ->assertSessionHasNoErrors();

        $protocol = Protocol::query()->where('title', 'Boaz recovery protocol')->firstOrFail();
        $requiredProtocolPhase = $protocol->phases()
            ->where('protocol_template_phase_id', $this->phaseDefinitions[0]->id)
            ->firstOrFail();

        $this->assertDatabaseHas('protocol_phase_supplements', [
            'protocol_phase_id' => $requiredProtocolPhase->id,
            'supplement_id' => $supplement->id,
            'dosage' => '2.75 eetlepel',
        ]);
    }

    public function test_supplements_must_belong_to_the_selected_phase(): void
    {
        $payload = $this->payload();
        $payload['phases'][0]['supplements'][0]['supplement_id'] = $this->secondPhaseSupplement->id;

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols', $payload)
            ->assertSessionHasErrors('phases.0.supplements.0.supplement_id');

        $this->assertDatabaseMissing('protocols', ['title' => 'Boaz recovery protocol']);
    }

    public function test_publishing_requires_a_complete_timed_plan(): void
    {
        $payload = $this->payload();
        $payload['published'] = true;
        $payload['started_at'] = null;

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols', $payload)
            ->assertSessionHasErrors('started_at');

        $payload['started_at'] = '2026-08-17';
        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocols', $payload)
            ->assertSessionHasNoErrors();

        $protocol = Protocol::query()->where('title', 'Boaz recovery protocol')->firstOrFail();
        $this->assertNotNull($protocol->published_at);
        $this->assertSame(8, $protocol->total_weeks);
    }

    public function test_a_protocol_copies_template_data_and_is_not_changed_by_later_template_updates(): void
    {
        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $this->payload());
        $protocol = Protocol::query()->where('title', 'Boaz recovery protocol')->firstOrFail();

        $phase = $protocol->phases()->where('protocol_template_phase_id', $this->phaseDefinitions[0]->id)->firstOrFail();
        $supplement = $phase->supplements()->where('supplement_id', $this->defaultSupplement->id)->firstOrFail();

        $this->assertSame('Configured phase 1', $phase->title);
        $this->assertSame('Description 1', $phase->description);
        $this->assertTrue($phase->required);
        $this->assertSame('per_kg', $supplement->dosis_type->value);
        $this->assertSame(0.32, $supplement->dosis);
        $this->assertSame('g', $supplement->unit->value);
        $this->assertTrue($supplement->add_by_default);
        $this->assertSame(4, $supplement->min_aantal_per_week);
        $this->assertSame(2, $supplement->rust_periode_in_weken);

        $this->phaseDefinitions[0]->update([
            'name' => 'Nieuwe templatenaam',
            'description' => 'Nieuwe templatebeschrijving',
            'required' => false,
        ]);
        $this->defaultSupplement->update([
            'name' => 'Nieuw templatesupplement',
            'dosis_type' => 'vast',
            'dosis' => 99,
            'unit' => 'ml',
            'add_by_default' => false,
            'min_aantal_per_week' => 1,
            'rust_periode_in_weken' => 9,
        ]);
        $this->protocolTemplate->update(['name' => 'Nieuwe protocoltemplatenaam']);
        $this->phaseDefinitions[0]->weeks()->create(['number' => 5]);

        $updatePayload = $this->payload();
        foreach ($protocol->phases()->with('supplements')->get() as $index => $storedPhase) {
            $updatePayload['phases'][$index]['id'] = $storedPhase->id;
            $updatePayload['phases'][$index]['client_key'] = $storedPhase->id;
            foreach ($updatePayload['phases'][$index]['supplements'] as $supplementIndex => $submittedSupplement) {
                $updatePayload['phases'][$index]['supplements'][$supplementIndex]['id'] = $storedPhase->supplements
                    ->firstWhere('supplement_id', $submittedSupplement['supplement_id'])?->id;
            }
        }

        $this->actingAs($this->admin, 'admin')
            ->put('/admin/protocols/'.$protocol->id, $updatePayload)
            ->assertSessionHasNoErrors();

        $protocol->refresh();
        $phase->refresh();
        $supplement->refresh();

        $this->assertSame('Darm protocol', $protocol->protocol_template_name);
        $this->assertSame('Configured phase 1', $phase->title);
        $this->assertSame('Description 1', $phase->description);
        $this->assertTrue($phase->required);
        $this->assertSame(4, $phase->weeks()->count());
        $this->assertSame('Psylliumzaad', $supplement->name);
        $this->assertSame('per_kg', $supplement->dosis_type->value);
        $this->assertSame(0.32, $supplement->dosis);
        $this->assertSame('g', $supplement->unit->value);
        $this->assertTrue($supplement->add_by_default);
        $this->assertSame(4, $supplement->min_aantal_per_week);
        $this->assertSame(2, $supplement->rust_periode_in_weken);
    }

    public function test_editing_a_template_phase_does_not_rename_an_existing_protocol_phase(): void
    {
        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $this->payload());
        $protocol = Protocol::query()->where('title', 'Boaz recovery protocol')->firstOrFail();

        $this->actingAs($this->admin, 'admin')
            ->put('/admin/protocol-settings/phases/'.$this->phaseDefinitions[0]->id, [
                'protocol_template_id' => $this->protocolTemplate->id,
                'name' => 'Maag en darmen',
                'description' => $this->phaseDefinitions[0]->description,
                'required' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('protocol_phases', [
            'protocol_id' => $protocol->id,
            'protocol_template_phase_id' => $this->phaseDefinitions[0]->id,
            'title' => 'Configured phase 1',
        ]);

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/protocols/'.$protocol->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page
                ->where('protocol.phases.0.title', 'Configured phase 1')
                ->where('protocol.phases.0.description', 'Description 1')
                ->where('protocol.phases.0.required', true));
    }

    public function test_compact_analysis_round_trips_without_replacing_the_original_notes(): void
    {
        $payload = $this->payload();
        $payload['published'] = true;
        $payload['analysis'] += [
            'summary' => 'De intake noemt wisselende signalen. Een samenhang is mogelijk, maar nog niet vastgesteld. Daarom kiezen we deze focus.',
            'focus_points' => [
                ['title' => 'Persoonlijk aandachtspunt', 'body' => 'Het individueel afgesproken doel ondersteunen.'],
                ['title' => 'Tweede aandachtspunt', 'body' => 'Werken aan het tweede afgesproken doel.'],
                ['title' => 'Derde aandachtspunt', 'body' => 'Het derde afgesproken doel ondersteunen.'],
            ],
            'observations' => ['Vergelijk de afgesproken signalen bij de evaluatie.'],
        ];
        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $payload)->assertSessionHasNoErrors();
        $protocol = Protocol::where('title', $payload['title'])->firstOrFail();
        $this->assertSame($payload['analysis']['summary'], $protocol->analysis->summary);
        $this->assertSame($payload['analysis']['focus_points'], $protocol->analysis->focus_points);
        $this->assertSame($payload['analysis']['observations'], $protocol->analysis->observations);
        $this->assertSame('Restore the gut first.', $protocol->analysis->cause);
        $this->actingAs($this->admin, 'admin')->get('/admin/protocols/'.$protocol->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page
                ->where('protocol.analysis.summary', $payload['analysis']['summary'])
                ->where('protocol.analysis.focus_points', $payload['analysis']['focus_points'])
                ->where('protocol.analysis.observations', $payload['analysis']['observations']));

        foreach ($protocol->phases as $index => $phase) {
            $payload['phases'][$index]['id'] = $phase->id;
            foreach ($payload['phases'][$index]['supplements'] as $supplementIndex => $supplement) {
                $payload['phases'][$index]['supplements'][$supplementIndex]['id'] = $phase->supplements
                    ->firstWhere('supplement_id', $supplement['supplement_id'])?->id;
            }
        }
        foreach ($protocol->analysis->advice as $index => $advice) {
            $payload['advice'][$index]['id'] = $advice->id;
        }

        $payload['analysis']['summary'] = 'Bijgestelde, genuanceerde samenvatting.';
        $payload['analysis']['focus_points'][0]['body'] = 'Het bijgestelde doel volgen.';
        $payload['analysis']['observations'] = ['Bespreek de bijgestelde observatie.'];
        $this->actingAs($this->admin, 'admin')->put('/admin/protocols/'.$protocol->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($payload['analysis']['summary'], $protocol->fresh()->analysis->summary);
        $this->assertSame($payload['analysis']['focus_points'], $protocol->fresh()->analysis->focus_points);
        $this->assertSame($payload['analysis']['observations'], $protocol->fresh()->analysis->observations);

        // An older form must not erase new content just because it omits these fields.
        $payload['analysis'] = ['cause' => 'Retained earlier notes.'];
        $this->actingAs($this->admin, 'admin')->put('/admin/protocols/'.$protocol->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Bijgestelde, genuanceerde samenvatting.', $protocol->fresh()->analysis->summary);

        $payload['analysis'] += ['summary' => null, 'focus_points' => [], 'observations' => []];
        $this->actingAs($this->admin, 'admin')->put('/admin/protocols/'.$protocol->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($protocol->fresh()->analysis->summary);
        $this->assertSame([], $protocol->fresh()->analysis->focus_points);
        $this->assertSame([], $protocol->fresh()->analysis->observations);
        $this->assertSame('Retained earlier notes.', $protocol->fresh()->analysis->cause);
    }

    public function test_compact_analysis_rejects_long_text_generic_categories_and_too_many_focus_points(): void
    {
        $payload = $this->payload();
        $payload['analysis'] += [
            'summary' => 'Eerste zin. Tweede zin. Derde zin. Vierde zin. Vijfde zin.',
            'focus_points' => array_fill(0, 5, ['title' => 'Management', 'body' => 'Eerste doel. Tweede doel.']),
            'observations' => [str_repeat('x', 161)],
        ];
        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $payload)
            ->assertSessionHasErrors(['analysis.summary', 'analysis.focus_points', 'analysis.focus_points.0.title', 'analysis.focus_points.0.body', 'analysis.observations.0']);
        $this->assertDatabaseMissing('protocols', ['title' => $payload['title']]);
    }

    public function test_publishing_compact_analysis_requires_observations(): void
    {
        $payload = $this->payload();
        $payload['published'] = true;
        $payload['analysis'] += ['summary' => 'Een korte persoonlijke samenvatting.', 'focus_points' => [], 'observations' => []];
        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $payload)
            ->assertSessionHasErrors(['analysis.observations']);
    }

    public function test_analysis_can_be_published_and_updated_without_removed_focus_fields(): void
    {
        $payload = $this->payload();
        $payload['published'] = true;
        $payload['analysis'] += [
            'summary' => 'Een korte persoonlijke samenvatting.',
            'observations' => ['Vergelijk de signalen bij de evaluatie.'],
        ];
        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', $payload)->assertSessionHasNoErrors();
        $protocol = Protocol::query()->firstOrFail();
        $this->assertNotNull($protocol->published_at);
        $this->assertSame([], $protocol->analysis->focus_points);

        // Hidden historical focus data must never be emptied by the new editor.
        $historicalFocus = [['title' => 'Eerder aandachtspunt', 'body' => 'Eerder afgesproken doel.']];
        $protocol->analysis->update(['focus_points' => $historicalFocus]);
        $update = $this->storedPayload($protocol);
        $update['published'] = true;
        $update['analysis'] = [...$payload['analysis'], 'summary' => 'Bijgestelde persoonlijke samenvatting.'];
        $this->put('/admin/protocols/'.$protocol->id, $update)->assertSessionHasNoErrors();
        $this->get(route('admin.protocols.edit', $protocol))->assertInertia(fn (Assert $page) => $page
            ->where('protocol.analysis.summary', 'Bijgestelde persoonlijke samenvatting.')
            ->where('protocol.analysis.observations', $payload['analysis']['observations'])
            ->where('protocol.analysis.focus_points', $historicalFocus));
    }

    public function test_failed_new_save_rolls_back_every_table_and_returns_a_clear_error(): void
    {
        AuditLog::creating(function (): void {
            throw new \RuntimeException('Simulated failure after saving the complete protocol');
        });

        try {
            $this->actingAs($this->admin, 'admin')->from('/admin/protocols/create')
                ->post('/admin/protocols', [...$this->payload(), 'published' => true])
                ->assertRedirect('/admin/protocols/create')->assertSessionHasErrors('save');
        } finally {
            AuditLog::flushEventListeners();
        }

        foreach (['protocols', 'protocol_phases', 'protocol_phase_weeks', 'protocol_phase_supplements', 'protocol_phase_supplement_weeks', 'protocol_analyses', 'protocol_advice', 'audit_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_failed_update_preserves_the_complete_published_protocol(): void
    {
        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', [...$this->payload(), 'published' => true])
            ->assertSessionHasNoErrors();
        $protocol = Protocol::query()->firstOrFail();
        $payload = $this->storedPayload($protocol);
        $tables = ['protocols', 'protocol_phases', 'protocol_phase_weeks', 'protocol_phase_supplements', 'protocol_phase_supplement_weeks', 'protocol_analyses', 'protocol_advice', 'audit_logs'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
        $payload['title'] = 'Must roll back';
        $payload['published'] = false;
        $payload['phases'][0]['week_count'] = 5;
        $payload['phases'][0]['supplements'][0]['dosage'] = 'Changed dosage';
        AuditLog::creating(function (): void {
            throw new \RuntimeException('Simulated failure after saving the complete protocol');
        });

        try {
            $this->from(route('admin.protocols.edit', $protocol))->put('/admin/protocols/'.$protocol->id, $payload)
                ->assertRedirect(route('admin.protocols.edit', $protocol))->assertSessionHasErrors('save');
        } finally {
            AuditLog::flushEventListeners();
        }

        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_new_and_existing_protocol_save_publish_and_reopen_with_current_content(): void
    {
        $selectedAdvice = [
            'voeding_advies_ids' => [VoedingAdvies::query()->create(['title' => 'Voeding behouden', 'description' => 'Voedingsadvies.'])->id],
            'management_advies_ids' => [ManagementAdvies::query()->create(['title' => 'Management behouden', 'description' => 'Managementadvies.'])->id],
            'beweging_advies_ids' => [BewegingAdvies::query()->create(['title' => 'Beweging behouden', 'description' => 'Bewegingsadvies.'])->id],
        ];
        $this->actingAs($this->admin, 'admin')->post('/admin/protocols', [...$this->payload(), ...$selectedAdvice])->assertSessionHasNoErrors();
        $protocol = Protocol::query()->firstOrFail();
        $this->assertNull($protocol->published_at);

        foreach ([true, false, true, true] as $index => $published) {
            $payload = [...$this->storedPayload($protocol), ...$selectedAdvice];
            $payload['published'] = $published;
            $payload['title'] = "Saved revision {$index}";
            $payload['analysis']['cause'] = "Analysis revision {$index}";
            $payload['phases'][0]['supplements'][0]['dosage'] = "Dose {$index}";
            $payload['phases'][0]['week_count'] = 5 + $index;
            $payload['customer_settings'] = ['target_weight_kg' => 510 + $index];
            $this->put('/admin/protocols/'.$protocol->id, $payload)->assertSessionHasNoErrors()->assertRedirect();
            $protocol->refresh();
            $this->assertSame($published, $protocol->published_at !== null);
            $this->get(route('admin.protocols.edit', $protocol))->assertInertia(fn (Assert $page) => $page
                ->where('protocol.id', $protocol->id)
                ->where('protocol.title', "Saved revision {$index}")
                ->where('protocol.analysis.cause', "Analysis revision {$index}")
                ->where('protocol.phases.0.supplements.0.dosage', "Dose {$index}")
                ->has('protocol.phases.0.weeks', 5 + $index)
                ->where('protocol.customer_settings.target_weight_kg', 510 + $index)
                ->has('protocol.voeding_adviezen', 1)
                ->where('protocol.voeding_adviezen.0.voeding_advies_id', $selectedAdvice['voeding_advies_ids'][0])
                ->has('protocol.management_adviezen', 1)
                ->where('protocol.management_adviezen.0.management_advies_id', $selectedAdvice['management_advies_ids'][0])
                ->has('protocol.beweging_adviezen', 1)
                ->where('protocol.beweging_adviezen.0.beweging_advies_id', $selectedAdvice['beweging_advies_ids'][0]));
            $this->assertDatabaseCount('protocols', 1);
            $this->assertDatabaseCount('protocol_phases', 3);
        }
    }

    private function storedPayload(Protocol $protocol): array
    {
        $protocol->load('phases.supplements', 'analysis.advice');
        $payload = $this->payload();
        foreach ($protocol->phases as $index => $phase) {
            $payload['phases'][$index]['id'] = $phase->id;
            foreach ($phase->supplements as $selectionIndex => $selection) {
                $payload['phases'][$index]['supplements'][$selectionIndex]['id'] = $selection->id;
            }
        }
        foreach ($protocol->analysis->advice as $index => $advice) {
            $payload['advice'][$index]['id'] = $advice->id;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'horse_id' => $this->horse->id,
            'protocol_template_id' => $this->protocolTemplate->id,
            'therapist_id' => $this->therapist->id,
            'title' => 'Boaz recovery protocol',
            'started_at' => '2026-08-17',
            'status' => 'paused',
            'published' => false,
            'analysis' => ['cause' => 'Restore the gut first.'],
            'advice' => [
                ['id' => null, 'icon_key' => 'leaf', 'title' => 'Nutrition', 'body' => 'Adjust roughage and supplements.'],
                ['id' => null, 'icon_key' => 'run', 'title' => 'Movement', 'body' => 'Build movement gradually.'],
            ],
            'voeding_advies_ids' => [],
            'management_advies_ids' => [],
            'beweging_advies_ids' => [],
            'phases' => [
                [
                    'id' => null,
                    'client_key' => 'phase-one',
                    'protocol_template_phase_id' => $this->phaseDefinitions[0]->id,
                    'title' => 'Phase 1 — Gut recovery',
                    'week_count' => 4,
                    'supplements' => [[
                        'id' => null,
                        'supplement_id' => $this->defaultSupplement->id,
                        'dosage' => '175 g',
                        'aantal_per_week' => 4,
                        'instructions' => 'Door het ruwvoer.',
                        'week_numbers' => [1, 2, 3, 4],
                    ]],
                ],
                [
                    'id' => null,
                    'client_key' => 'phase-two',
                    'protocol_template_phase_id' => $this->phaseDefinitions[1]->id,
                    'title' => 'Phase 2 — Rebuild',
                    'week_count' => 2,
                    'supplements' => [[
                        'id' => null,
                        'supplement_id' => $this->secondPhaseSupplement->id,
                        'dosage' => '20 g',
                        'aantal_per_week' => 4,
                        'instructions' => null,
                        'week_numbers' => [1, 2],
                    ]],
                ],
                [
                    'id' => null,
                    'client_key' => 'phase-three',
                    'protocol_template_phase_id' => $this->phaseDefinitions[2]->id,
                    'title' => 'Phase 3 — Stabilize',
                    'week_count' => 2,
                    'supplements' => [],
                ],
            ],
        ];
    }
}
