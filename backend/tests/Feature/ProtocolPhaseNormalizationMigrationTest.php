<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\Protocol;
use App\Models\ProtocolPhase;
use App\Models\ProtocolPhaseItem;
use App\Models\ProtocolTask;
use App\Models\ProtocolType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtocolPhaseNormalizationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_existing_protocols_without_losing_extra_phase_content(): void
    {
        $owner = User::factory()->create();
        $horse = Horse::query()->create([
            'owner_id' => $owner->id,
            'name' => 'Nova',
            'status' => 'active',
        ]);
        $protocolType = ProtocolType::query()->create(['name' => 'Migration test type']);
        $phaseDefinitions = collect(range(1, 4))
            ->map(fn (int $phaseNumber) => $protocolType->phases()->create([
                'order' => $phaseNumber,
                'name' => "Definition {$phaseNumber}",
                'required' => $phaseNumber === 1,
            ]));

        $longProtocol = Protocol::query()->create([
            'horse_id' => $horse->id,
            'protocol_type_id' => $protocolType->id,
            'title' => 'Four phases',
            'total_weeks' => 8,
            'current_week' => 5,
            'status' => 'active',
        ]);
        $longPhases = collect();
        foreach (range(1, 4) as $phaseNumber) {
            $longPhases->push(ProtocolPhase::query()->create([
                'protocol_id' => $longProtocol->id,
                'protocol_type_phase_id' => $phaseDefinitions[$phaseNumber - 1]->id,
                'order' => $phaseNumber - 1,
                'title' => "Phase {$phaseNumber}",
                'state' => $phaseNumber < 3 ? 'done' : ($phaseNumber === 3 ? 'active' : 'upcoming'),
                'week_start' => ($phaseNumber - 1) * 2 + 1,
                'week_end' => $phaseNumber * 2,
            ]));
        }
        $extraItem = ProtocolPhaseItem::query()->create([
            'phase_id' => $longPhases[3]->id,
            'order' => 0,
            'label' => 'Preserve this summary',
        ]);
        $extraTask = ProtocolTask::query()->create([
            'protocol_id' => $longProtocol->id,
            'phase_id' => $longPhases[3]->id,
            'label' => 'Preserve this task',
            'kind' => 'care',
            'order' => 0,
        ]);

        $shortProtocol = Protocol::query()->create([
            'horse_id' => $horse->id,
            'protocol_type_id' => $protocolType->id,
            'title' => 'Two phases',
            'total_weeks' => 6,
            'current_week' => 2,
            'status' => 'active',
        ]);
        foreach (range(1, 2) as $phaseNumber) {
            ProtocolPhase::query()->create([
                'protocol_id' => $shortProtocol->id,
                'protocol_type_phase_id' => $phaseDefinitions[$phaseNumber - 1]->id,
                'order' => $phaseNumber - 1,
                'title' => "Phase {$phaseNumber}",
                'state' => $phaseNumber === 1 ? 'active' : 'upcoming',
                'week_start' => ($phaseNumber - 1) * 2 + 1,
                'week_end' => $phaseNumber * 2,
            ]);
        }

        $preparedProtocol = Protocol::query()->create([
            'horse_id' => $horse->id,
            'protocol_type_id' => $protocolType->id,
            'title' => 'Preparation plus three phases',
            'total_weeks' => 8,
            'current_week' => 2,
            'status' => 'active',
        ]);
        $preparedPhases = collect();
        foreach (['Voorbereiding', 'Phase 1', 'Phase 2', 'Phase 3'] as $order => $title) {
            $preparedPhases->push(ProtocolPhase::query()->create([
                'protocol_id' => $preparedProtocol->id,
                'protocol_type_phase_id' => $phaseDefinitions[$order]->id,
                'order' => $order,
                'title' => $title,
                'state' => $order === 1 ? 'active' : ($order === 0 ? 'done' : 'upcoming'),
                'week_start' => $order === 0 ? 0 : (($order - 1) * 2 + 1),
                'week_end' => $order === 0 ? 0 : $order * 2,
            ]));
        }
        $preparationItem = ProtocolPhaseItem::query()->create([
            'phase_id' => $preparedPhases[0]->id,
            'order' => 0,
            'label' => 'Preparation summary',
        ]);
        $preparationTask = ProtocolTask::query()->create([
            'protocol_id' => $preparedProtocol->id,
            'phase_id' => $preparedPhases[0]->id,
            'label' => 'Preparation task',
            'kind' => 'care',
            'order' => 0,
        ]);

        $migration = require database_path('migrations/2026_08_19_000001_normalize_protocols_to_three_phases.php');
        $migration->up();

        $longProtocol->load('phases');
        $thirdPhase = $longProtocol->phases[2];

        $this->assertCount(3, $longProtocol->phases);
        $this->assertSame(8, $thirdPhase->week_end);
        $this->assertDatabaseHas('protocol_phase_items', [
            'id' => $extraItem->id,
            'phase_id' => $thirdPhase->id,
        ]);
        $this->assertDatabaseHas('protocol_tasks', [
            'id' => $extraTask->id,
            'phase_id' => $thirdPhase->id,
        ]);
        $this->assertSame(3, $shortProtocol->phases()->count());

        $preparedProtocol->load('phases');
        $this->assertCount(3, $preparedProtocol->phases);
        $this->assertSame('Phase 1', $preparedProtocol->phases[0]->title);
        $this->assertDatabaseHas('protocol_phase_items', [
            'id' => $preparationItem->id,
            'phase_id' => $preparedProtocol->phases[0]->id,
        ]);
        $this->assertDatabaseHas('protocol_tasks', [
            'id' => $preparationTask->id,
            'phase_id' => $preparedProtocol->phases[0]->id,
        ]);
    }
}
