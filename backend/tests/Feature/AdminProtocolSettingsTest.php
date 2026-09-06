<?php

namespace Tests\Feature;

use App\Enums\SupplementDoseType;
use App\Enums\SupplementDoseUnit;
use App\Enums\SupplementType;
use App\Models\AdminUser;
use App\Models\ProtocolTemplate;
use App\Models\ProtocolTemplatePhase;
use App\Models\Supplement;
use App\Models\SupplementWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminProtocolSettingsTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::query()->create([
            'name' => 'Protocol Settings Admin',
            'email' => 'protocol-settings@example.com',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);
    }

    public function test_admin_can_open_protocol_settings_from_its_page(): void
    {
        $type = ProtocolTemplate::query()->create(['name' => 'Recovery']);
        $phase = $type->phases()->create([
            'order' => 1,
            'name' => 'Restore',
            'description' => 'Restore the digestive system.',
            'required' => true,
        ]);
        $week = $phase->weeks()->create(['number' => 1]);
        $supplement = $phase->supplements()->create([
            'name' => 'Psyllium',
            'description' => 'Supports digestion.',
            'supplement_type' => SupplementType::Herb,
            'dosis_type' => SupplementDoseType::PerKilogram,
            'dosis' => 0.25,
            'unit' => SupplementDoseUnit::Gram,
            'add_by_default' => true,
        ]);
        SupplementWeek::query()->create([
            'supplement_id' => $supplement->id,
            'protocol_template_phase_week_id' => $week->id,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/protocol-settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProtocolSettings/Index')
                ->has('protocolTemplates', 1)
                ->where('protocolTemplates.0.name', 'Recovery')
                ->missing('protocolTemplates.0.version')
                ->where('protocolTemplates.0.phases.0.name', 'Restore')
                ->where('protocolTemplates.0.phases.0.required', true)
                ->where('protocolTemplates.0.phases.0.weeks.0.number', 1)
                ->where('protocolTemplates.0.phases.0.supplements.0.name', 'Psyllium')
                ->where('protocolTemplates.0.phases.0.supplements.0.supplement_type', 'kruid')
                ->where('protocolTemplates.0.phases.0.supplements.0.dosis_type', 'per_kg')
                ->where('protocolTemplates.0.phases.0.supplements.0.dosis', 0.25)
                ->where('protocolTemplates.0.phases.0.supplements.0.unit', 'g')
                ->where('protocolTemplates.0.phases.0.supplements.0.weeks.0.id', $week->id));
    }

    public function test_admin_can_create_update_and_remove_protocol_templates(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-settings/templates', ['name' => 'General recovery'])
            ->assertSessionHasNoErrors();

        $type = ProtocolTemplate::query()->where('name', 'General recovery')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'target_type' => 'ProtocolTemplate',
            'target_id' => $type->id,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/protocol-settings/templates/{$type->id}", ['name' => 'Digestive recovery'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('protocol_templates', ['id' => $type->id, 'name' => 'Digestive recovery']);

        $this->actingAs($this->admin, 'admin')
            ->delete("/admin/protocol-settings/templates/{$type->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('protocol_templates', ['id' => $type->id]);
    }

    public function test_admin_can_configure_phases_and_move_them_between_protocol_templates(): void
    {
        $firstType = ProtocolTemplate::query()->create(['name' => 'Digestive']);
        $secondType = ProtocolTemplate::query()->create(['name' => 'Mobility']);

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-settings/phases', [
                'protocol_template_id' => $firstType->id,
                'name' => 'Gut reset',
                'description' => 'A deliberately longer phase description.',
                'required' => true,
                'start_after_previous_phase_weeks' => 2,
            ])
            ->assertSessionHasNoErrors();

        $phase = ProtocolTemplatePhase::query()->where('name', 'Gut reset')->firstOrFail();
        $this->assertSame(1, $phase->order);
        $this->assertTrue($phase->required);
        $this->assertSame($firstType->id, $phase->protocol_template_id);
        $this->assertSame(2, $phase->start_after_previous_phase_weeks);

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/protocol-settings/phases/{$phase->id}", [
                'protocol_template_id' => $secondType->id,
                'name' => 'Mobility reset',
                'description' => null,
                'required' => false,
                'start_after_previous_phase_weeks' => null,
            ])
            ->assertSessionHasNoErrors();

        $phase->refresh();
        $this->assertSame($secondType->id, $phase->protocol_template_id);
        $this->assertSame('Mobility reset', $phase->name);
        $this->assertFalse($phase->required);
        $this->assertNull($phase->start_after_previous_phase_weeks);

        $this->actingAs($this->admin, 'admin')
            ->delete("/admin/protocol-settings/phases/{$phase->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('protocol_template_phases', ['id' => $phase->id]);
    }

    public function test_phase_start_delay_can_be_zero_for_parallel_phases(): void
    {
        $template = ProtocolTemplate::query()->create(['name' => 'Parallel phases']);

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-settings/phases', [
                'protocol_template_id' => $template->id,
                'name' => 'Mineralen aanvullen',
                'description' => null,
                'required' => false,
                'start_after_previous_phase_weeks' => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('protocol_template_phases', [
            'protocol_template_id' => $template->id,
            'name' => 'Mineralen aanvullen',
            'start_after_previous_phase_weeks' => 0,
        ]);
    }

    public function test_admin_can_duplicate_a_phase_with_its_weeks_supplements_and_schedule(): void
    {
        $template = ProtocolTemplate::query()->create(['name' => 'Duplicate phase']);
        $template->phases()->create([
            'order' => 1,
            'name' => 'Preparation',
            'required' => true,
        ]);
        $source = $template->phases()->create([
            'order' => 2,
            'name' => 'Recovery',
            'description' => 'Support recovery.',
            'required' => false,
            'start_after_previous_phase_weeks' => 2,
        ]);
        $after = $template->phases()->create([
            'order' => 3,
            'name' => 'Maintenance',
            'required' => false,
        ]);
        $weeks = collect(range(1, 3))->map(
            fn (int $number) => $source->weeks()->create(['number' => $number]),
        );
        $psyllium = $source->supplements()->create([
            'name' => 'Psyllium',
            'description' => 'Supports digestion.',
            'instructions' => 'Mix with water.',
            'supplement_type' => SupplementType::Herb,
            'dosis_type' => SupplementDoseType::Fixed,
            'dosis' => 20,
            'unit' => SupplementDoseUnit::Gram,
            'add_by_default' => true,
            'max_aantal_in_fase' => 2,
            'min_aantal_per_week' => 5,
            'rust_periode_in_weken' => 1,
        ]);
        $zinc = $source->supplements()->create([
            'name' => 'Zinc',
            'supplement_type' => SupplementType::Mineral,
            'add_by_default' => false,
        ]);
        $psyllium->weeks()->attach([$weeks[0]->id, $weeks[2]->id]);
        $zinc->weeks()->attach($weeks[1]->id);

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/protocol-settings/phases/{$source->id}/duplicate")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Fase gedupliceerd.');

        $phases = $template->phases()->with(['weeks', 'supplements.weeks'])->get();
        $copy = $phases->firstWhere('name', 'Recovery (kopie)');

        $this->assertNotNull($copy);
        $this->assertSame([$source->id, $copy->id, $after->id], $phases->slice(1)->pluck('id')->all());
        $this->assertSame([1, 2, 3, 4], $phases->pluck('order')->all());
        $this->assertSame('Support recovery.', $copy->description);
        $this->assertFalse($copy->required);
        $this->assertSame(2, $copy->start_after_previous_phase_weeks);
        $this->assertSame([1, 2, 3], $copy->weeks->pluck('number')->all());
        $this->assertEmpty($copy->weeks->pluck('id')->intersect($weeks->pluck('id')));

        $copiedPsyllium = $copy->supplements->firstWhere('name', 'Psyllium');
        $copiedZinc = $copy->supplements->firstWhere('name', 'Zinc');
        $this->assertNotNull($copiedPsyllium);
        $this->assertNotNull($copiedZinc);
        $this->assertNotSame($psyllium->id, $copiedPsyllium->id);
        $this->assertSame('Mix with water.', $copiedPsyllium->instructions);
        $this->assertSame(SupplementDoseType::Fixed, $copiedPsyllium->dosis_type);
        $this->assertSame(SupplementDoseUnit::Gram, $copiedPsyllium->unit);
        $this->assertTrue($copiedPsyllium->add_by_default);
        $this->assertSame([1, 3], $copiedPsyllium->weeks->pluck('number')->all());
        $this->assertSame([2], $copiedZinc->weeks->pluck('number')->all());
        $this->assertSame([1, 2, 3], $source->weeks()->pluck('number')->all());
        $this->assertSame([1, 3], $psyllium->weeks()->pluck('number')->all());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'target_type' => 'ProtocolTemplatePhase',
            'target_id' => $copy->id,
        ]);
    }

    public function test_admin_can_configure_the_fixed_phase_order(): void
    {
        $type = ProtocolTemplate::query()->create(['name' => 'Ordered protocol']);
        $first = $type->phases()->create([
            'order' => 1,
            'name' => 'First',
            'required' => true,
        ]);
        $second = $type->phases()->create([
            'order' => 2,
            'name' => 'Second',
            'required' => false,
        ]);
        $third = $type->phases()->create([
            'order' => 3,
            'name' => 'Third',
            'required' => true,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->patch("/admin/protocol-settings/phases/{$second->id}/order", ['direction' => 'up'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [$second->id, $first->id, $third->id],
            $type->phases()->pluck('id')->all(),
        );
        $this->assertSame([1, 2, 3], $type->phases()->pluck('order')->all());

        $this->actingAs($this->admin, 'admin')
            ->patch("/admin/protocol-settings/phases/{$second->id}/order", ['direction' => 'up'])
            ->assertSessionHasNoErrors();

        $this->assertSame([$second->id, $first->id, $third->id], $type->phases()->pluck('id')->all());
    }

    public function test_phase_weeks_are_numbered_and_renumbered_automatically(): void
    {
        $type = ProtocolTemplate::query()->create(['name' => 'Skin']);
        $phase = $type->phases()->create([
            'order' => 1,
            'name' => 'Calm inflammation',
            'description' => null,
            'required' => true,
        ]);

        foreach (range(1, 3) as $_) {
            $this->actingAs($this->admin, 'admin')
                ->post("/admin/protocol-settings/phases/{$phase->id}/weeks")
                ->assertSessionHasNoErrors();
        }

        $this->assertSame([1, 2, 3], $phase->weeks()->pluck('number')->all());
        $secondWeek = $phase->weeks()->where('number', 2)->firstOrFail();

        $this->actingAs($this->admin, 'admin')
            ->delete("/admin/protocol-settings/weeks/{$secondWeek->id}")
            ->assertSessionHasNoErrors();

        $this->assertSame([1, 2], $phase->weeks()->pluck('number')->all());
    }

    public function test_removing_a_protocol_template_cascades_to_its_phases_and_weeks(): void
    {
        $type = ProtocolTemplate::query()->create(['name' => 'Cascade test']);
        $phase = $type->phases()->create([
            'order' => 1,
            'name' => 'Phase',
            'description' => null,
            'required' => false,
        ]);
        $week = $phase->weeks()->create(['number' => 1]);
        $supplement = $phase->supplements()->create([
            'name' => 'Cascade supplement',
            'description' => null,
            'supplement_type' => SupplementType::Supplement,
            'add_by_default' => false,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->delete("/admin/protocol-settings/templates/{$type->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('protocol_template_phases', ['id' => $phase->id]);
        $this->assertDatabaseMissing('protocol_template_phase_weeks', ['id' => $week->id]);
        $this->assertDatabaseMissing('supplements', ['id' => $supplement->id]);
    }

    public function test_admin_can_create_update_move_and_remove_a_supplement(): void
    {
        $type = ProtocolTemplate::query()->create(['name' => 'Supplement settings']);
        $firstPhase = $type->phases()->create([
            'order' => 1,
            'name' => 'First phase',
            'description' => null,
            'required' => true,
        ]);
        $secondPhase = $type->phases()->create([
            'order' => 2,
            'name' => 'Second phase',
            'description' => null,
            'required' => false,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-settings/supplements', [
                'protocol_template_phase_id' => $firstPhase->id,
                'name' => 'Zinc',
                'description' => 'Supports the skin.',
                'instructions' => 'Mix thoroughly with the feed.',
                'supplement_type' => 'mineraal',
                'dosis_type' => 'vast',
                'dosis' => 40,
                'unit' => 'g',
            ])
            ->assertSessionHasNoErrors();

        $supplement = Supplement::query()->where('name', 'Zinc')->firstOrFail();
        $this->assertSame(SupplementType::Mineral, $supplement->supplement_type);
        $this->assertSame(SupplementDoseType::Fixed, $supplement->dosis_type);
        $this->assertSame(40.0, $supplement->dosis);
        $this->assertSame(SupplementDoseUnit::Gram, $supplement->unit);
        $this->assertSame('Mix thoroughly with the feed.', $supplement->instructions);
        $this->assertFalse($supplement->add_by_default);
        $this->assertNull($supplement->max_aantal_in_fase);
        $this->assertSame(4, $supplement->min_aantal_per_week);
        $this->assertSame(2, $supplement->rust_periode_in_weken);

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/protocol-settings/supplements/{$supplement->id}", [
                'protocol_template_phase_id' => $secondPhase->id,
                'name' => 'Zinc complex',
                'description' => 'Updated description.',
                'instructions' => 'Divide over two feedings.',
                'supplement_type' => 'supplement',
                'dosis_type' => 'per_kg',
                'dosis' => 0.08,
                'unit' => 'g',
                'add_by_default' => true,
                'max_aantal_in_fase' => 3,
                'min_aantal_per_week' => 5,
                'rust_periode_in_weken' => 1,
            ])
            ->assertSessionHasNoErrors();

        $supplement->refresh();
        $this->assertSame($secondPhase->id, $supplement->protocol_template_phase_id);
        $this->assertSame('Zinc complex', $supplement->name);
        $this->assertSame(SupplementType::Supplement, $supplement->supplement_type);
        $this->assertSame(SupplementDoseType::PerKilogram, $supplement->dosis_type);
        $this->assertSame(0.08, $supplement->dosis);
        $this->assertSame(SupplementDoseUnit::Gram, $supplement->unit);
        $this->assertSame('Divide over two feedings.', $supplement->instructions);
        $this->assertTrue($supplement->add_by_default);
        $this->assertSame(3, $supplement->max_aantal_in_fase);
        $this->assertSame(5, $supplement->min_aantal_per_week);
        $this->assertSame(1, $supplement->rust_periode_in_weken);

        $this->actingAs($this->admin, 'admin')
            ->delete("/admin/protocol-settings/supplements/{$supplement->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('supplements', ['id' => $supplement->id]);
    }

    public function test_supplement_type_must_be_one_of_the_configured_enum_values(): void
    {
        $type = ProtocolTemplate::query()->create(['name' => 'Validation']);
        $phase = $type->phases()->create([
            'order' => 1,
            'name' => 'Phase',
            'description' => null,
            'required' => false,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-settings/supplements', [
                'protocol_template_phase_id' => $phase->id,
                'name' => 'Invalid',
                'supplement_type' => 'vitamine',
            ])
            ->assertSessionHasErrors('supplement_type');

        $this->assertDatabaseMissing('supplements', ['name' => 'Invalid']);
    }

    public function test_supplement_dosage_fields_are_validated_as_a_complete_set(): void
    {
        $type = ProtocolTemplate::query()->create(['name' => 'Dosage validation']);
        $phase = $type->phases()->create([
            'order' => 1,
            'name' => 'Phase',
            'description' => null,
            'required' => false,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-settings/supplements', [
                'protocol_template_phase_id' => $phase->id,
                'name' => 'Incomplete dosage',
                'supplement_type' => 'kruid',
                'dosis_type' => 'per_kg',
                'unit' => 'kg',
            ])
            ->assertSessionHasErrors(['dosis', 'unit']);

        $this->assertDatabaseMissing('supplements', ['name' => 'Incomplete dosage']);
    }

    public function test_supplement_supports_per_600_kg_dosage_and_spoon_units(): void
    {
        $type = ProtocolTemplate::query()->create(['name' => 'Extended dosage']);
        $phase = $type->phases()->create([
            'order' => 1,
            'name' => 'Phase',
            'required' => true,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-settings/supplements', [
                'protocol_template_phase_id' => $phase->id,
                'name' => 'Herbal mix',
                'supplement_type' => 'kruid',
                'dosis_type' => 'per_600_kg',
                'dosis' => 3,
                'unit' => 'theelepel',
            ])
            ->assertSessionHasNoErrors();

        $supplement = Supplement::query()->where('name', 'Herbal mix')->firstOrFail();
        $this->assertSame(SupplementDoseType::Per600Kilograms, $supplement->dosis_type);
        $this->assertSame(SupplementDoseUnit::Teaspoon, $supplement->unit);

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/protocol-settings/supplements/{$supplement->id}", [
                'protocol_template_phase_id' => $phase->id,
                'name' => 'Herbal mix',
                'supplement_type' => 'kruid',
                'dosis_type' => 'per_600_kg',
                'dosis' => 3,
                'unit' => 'eetlepel',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(SupplementDoseUnit::Tablespoon, $supplement->fresh()->unit);
    }

    public function test_supplement_supports_drop_pill_and_capsule_units(): void
    {
        $template = ProtocolTemplate::query()->create(['name' => 'Drop and pill dosage']);
        $phase = $template->phases()->create([
            'order' => 1,
            'name' => 'Phase',
            'required' => true,
        ]);

        foreach ([
            'druppels' => SupplementDoseUnit::Drops,
            'pillen' => SupplementDoseUnit::Pills,
            'capsules' => SupplementDoseUnit::Capsules,
        ] as $unit => $expectedUnit) {
            $this->actingAs($this->admin, 'admin')
                ->post('/admin/protocol-settings/supplements', [
                    'protocol_template_phase_id' => $phase->id,
                    'name' => ucfirst($unit),
                    'supplement_type' => 'supplement',
                    'dosis_type' => 'vast',
                    'dosis' => 2,
                    'unit' => $unit,
                ])
                ->assertSessionHasNoErrors();

            $this->assertSame(
                $expectedUnit,
                Supplement::query()->where('name', ucfirst($unit))->sole()->unit,
            );
        }
    }

    public function test_admin_can_toggle_a_supplement_for_a_week_in_the_same_phase(): void
    {
        $type = ProtocolTemplate::query()->create(['name' => 'Gantt planning']);
        $phase = $type->phases()->create([
            'order' => 1,
            'name' => 'Active phase',
            'description' => null,
            'required' => true,
        ]);
        $otherPhase = $type->phases()->create([
            'order' => 2,
            'name' => 'Other phase',
            'description' => null,
            'required' => false,
        ]);
        $week = $phase->weeks()->create(['number' => 1]);
        $otherWeek = $otherPhase->weeks()->create(['number' => 1]);
        $supplement = $phase->supplements()->create([
            'name' => 'Chamomile',
            'description' => null,
            'supplement_type' => SupplementType::Herb,
            'add_by_default' => true,
        ]);

        $url = "/admin/protocol-settings/supplements/{$supplement->id}/weeks/{$week->id}";

        $this->actingAs($this->admin, 'admin')->put($url)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('supplement_weeks', [
            'supplement_id' => $supplement->id,
            'protocol_template_phase_week_id' => $week->id,
        ]);

        $this->actingAs($this->admin, 'admin')->put($url)->assertSessionHasNoErrors();
        $this->assertSame(1, SupplementWeek::query()->count());

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/protocol-settings/supplements/{$supplement->id}/weeks/{$otherWeek->id}")
            ->assertUnprocessable();

        $supplement->refresh();

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/protocol-settings/supplements/{$supplement->id}", [
                'protocol_template_phase_id' => $otherPhase->id,
                'name' => $supplement->name,
                'description' => $supplement->description,
                'supplement_type' => $supplement->supplement_type->value,
                'add_by_default' => $supplement->add_by_default,
                'max_aantal_in_fase' => $supplement->max_aantal_in_fase,
                'min_aantal_per_week' => $supplement->min_aantal_per_week,
                'rust_periode_in_weken' => $supplement->rust_periode_in_weken,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('supplement_weeks', [
            'supplement_id' => $supplement->id,
            'protocol_template_phase_week_id' => $week->id,
        ]);

        $otherUrl = "/admin/protocol-settings/supplements/{$supplement->id}/weeks/{$otherWeek->id}";
        $this->actingAs($this->admin, 'admin')->put($otherUrl)->assertSessionHasNoErrors();
        $this->actingAs($this->admin, 'admin')->delete($otherUrl)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('supplement_weeks', [
            'supplement_id' => $supplement->id,
            'protocol_template_phase_week_id' => $otherWeek->id,
        ]);
    }

    public function test_non_admin_cannot_manage_protocol_settings(): void
    {
        $support = AdminUser::query()->create([
            'name' => 'Support User',
            'email' => 'support-protocol-settings@example.com',
            'password' => 'password',
            'role' => 'support',
            'active' => true,
        ]);

        $this->actingAs($support, 'admin')
            ->get('/admin/protocol-settings')
            ->assertForbidden();
    }
}
