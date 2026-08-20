<?php

namespace Tests\Feature;

use App\Enums\SupplementType;
use App\Models\AdminUser;
use App\Models\ProtocolType;
use App\Models\ProtocolTypePhase;
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
        $type = ProtocolType::query()->create(['name' => 'Recovery']);
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
            'add_by_default' => true,
        ]);
        SupplementWeek::query()->create([
            'supplement_id' => $supplement->id,
            'protocol_type_phase_week_id' => $week->id,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/protocol-settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProtocolSettings/Index')
                ->has('protocolTypes', 1)
                ->where('protocolTypes.0.name', 'Recovery')
                ->where('protocolTypes.0.phases.0.name', 'Restore')
                ->where('protocolTypes.0.phases.0.required', true)
                ->where('protocolTypes.0.phases.0.weeks.0.number', 1)
                ->where('protocolTypes.0.phases.0.supplements.0.name', 'Psyllium')
                ->where('protocolTypes.0.phases.0.supplements.0.supplement_type', 'kruid')
                ->where('protocolTypes.0.phases.0.supplements.0.weeks.0.id', $week->id));
    }

    public function test_admin_can_create_update_and_remove_protocol_types(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-settings/types', ['name' => 'General recovery'])
            ->assertSessionHasNoErrors();

        $type = ProtocolType::query()->where('name', 'General recovery')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'target_type' => 'ProtocolType',
            'target_id' => $type->id,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/protocol-settings/types/{$type->id}", ['name' => 'Digestive recovery'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('protocol_types', ['id' => $type->id, 'name' => 'Digestive recovery']);

        $this->actingAs($this->admin, 'admin')
            ->delete("/admin/protocol-settings/types/{$type->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('protocol_types', ['id' => $type->id]);
    }

    public function test_admin_can_configure_phases_and_move_them_between_protocol_types(): void
    {
        $firstType = ProtocolType::query()->create(['name' => 'Digestive']);
        $secondType = ProtocolType::query()->create(['name' => 'Mobility']);

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-settings/phases', [
                'protocol_type_id' => $firstType->id,
                'name' => 'Gut reset',
                'description' => 'A deliberately longer phase description.',
                'required' => true,
            ])
            ->assertSessionHasNoErrors();

        $phase = ProtocolTypePhase::query()->where('name', 'Gut reset')->firstOrFail();
        $this->assertSame(1, $phase->order);
        $this->assertTrue($phase->required);
        $this->assertSame($firstType->id, $phase->protocol_type_id);

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/protocol-settings/phases/{$phase->id}", [
                'protocol_type_id' => $secondType->id,
                'name' => 'Mobility reset',
                'description' => null,
                'required' => false,
            ])
            ->assertSessionHasNoErrors();

        $phase->refresh();
        $this->assertSame($secondType->id, $phase->protocol_type_id);
        $this->assertSame('Mobility reset', $phase->name);
        $this->assertFalse($phase->required);

        $this->actingAs($this->admin, 'admin')
            ->delete("/admin/protocol-settings/phases/{$phase->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('protocol_type_phases', ['id' => $phase->id]);
    }

    public function test_phase_weeks_are_numbered_and_renumbered_automatically(): void
    {
        $type = ProtocolType::query()->create(['name' => 'Skin']);
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

    public function test_removing_a_protocol_type_cascades_to_its_phases_and_weeks(): void
    {
        $type = ProtocolType::query()->create(['name' => 'Cascade test']);
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
            ->delete("/admin/protocol-settings/types/{$type->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('protocol_type_phases', ['id' => $phase->id]);
        $this->assertDatabaseMissing('protocol_type_phase_weeks', ['id' => $week->id]);
        $this->assertDatabaseMissing('supplements', ['id' => $supplement->id]);
    }

    public function test_admin_can_create_update_move_and_remove_a_supplement(): void
    {
        $type = ProtocolType::query()->create(['name' => 'Supplement settings']);
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
                'protocol_type_phase_id' => $firstPhase->id,
                'name' => 'Zinc',
                'description' => 'Supports the skin.',
                'supplement_type' => 'mineraal',
            ])
            ->assertSessionHasNoErrors();

        $supplement = Supplement::query()->where('name', 'Zinc')->firstOrFail();
        $this->assertSame(SupplementType::Mineral, $supplement->supplement_type);
        $this->assertFalse($supplement->add_by_default);
        $this->assertNull($supplement->max_aantal_in_fase);
        $this->assertSame(4, $supplement->min_aantal_per_week);
        $this->assertSame(2, $supplement->rust_periode_in_weken);

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/protocol-settings/supplements/{$supplement->id}", [
                'protocol_type_phase_id' => $secondPhase->id,
                'name' => 'Zinc complex',
                'description' => 'Updated description.',
                'supplement_type' => 'supplement',
                'add_by_default' => true,
                'max_aantal_in_fase' => 3,
                'min_aantal_per_week' => 5,
                'rust_periode_in_weken' => 1,
            ])
            ->assertSessionHasNoErrors();

        $supplement->refresh();
        $this->assertSame($secondPhase->id, $supplement->protocol_type_phase_id);
        $this->assertSame('Zinc complex', $supplement->name);
        $this->assertSame(SupplementType::Supplement, $supplement->supplement_type);
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
        $type = ProtocolType::query()->create(['name' => 'Validation']);
        $phase = $type->phases()->create([
            'order' => 1,
            'name' => 'Phase',
            'description' => null,
            'required' => false,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/protocol-settings/supplements', [
                'protocol_type_phase_id' => $phase->id,
                'name' => 'Invalid',
                'supplement_type' => 'vitamine',
            ])
            ->assertSessionHasErrors('supplement_type');

        $this->assertDatabaseMissing('supplements', ['name' => 'Invalid']);
    }

    public function test_admin_can_toggle_a_supplement_for_a_week_in_the_same_phase(): void
    {
        $type = ProtocolType::query()->create(['name' => 'Gantt planning']);
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
            'protocol_type_phase_week_id' => $week->id,
        ]);

        $this->actingAs($this->admin, 'admin')->put($url)->assertSessionHasNoErrors();
        $this->assertSame(1, SupplementWeek::query()->count());

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/protocol-settings/supplements/{$supplement->id}/weeks/{$otherWeek->id}")
            ->assertUnprocessable();

        $supplement->refresh();

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/protocol-settings/supplements/{$supplement->id}", [
                'protocol_type_phase_id' => $otherPhase->id,
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
            'protocol_type_phase_week_id' => $week->id,
        ]);

        $otherUrl = "/admin/protocol-settings/supplements/{$supplement->id}/weeks/{$otherWeek->id}";
        $this->actingAs($this->admin, 'admin')->put($otherUrl)->assertSessionHasNoErrors();
        $this->actingAs($this->admin, 'admin')->delete($otherUrl)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('supplement_weeks', [
            'supplement_id' => $supplement->id,
            'protocol_type_phase_week_id' => $otherWeek->id,
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
