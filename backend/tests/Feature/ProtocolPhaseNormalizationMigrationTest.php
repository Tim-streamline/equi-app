<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\Protocol;
use App\Models\ProtocolPhase;
use App\Models\ProtocolTemplate;
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
        $protocolTemplate = ProtocolTemplate::query()->create(['name' => 'Migration test type']);
        $phaseDefinitions = collect(range(1, 4))
            ->map(fn (int $phaseNumber) => $protocolTemplate->phases()->create([
                'order' => $phaseNumber,
                'name' => "Definition {$phaseNumber}",
                'required' => $phaseNumber === 1,
            ]));

        $longProtocol = Protocol::query()->create([
            'horse_id' => $horse->id,
            'protocol_template_id' => $protocolTemplate->id,
            'title' => 'Four phases',
            'total_weeks' => 8,
            'current_week' => 5,
            'status' => 'active',
        ]);
        foreach (range(1, 4) as $phaseNumber) {
            ProtocolPhase::query()->create([
                'protocol_id' => $longProtocol->id,
                'protocol_template_phase_id' => $phaseDefinitions[$phaseNumber - 1]->id,
                'order' => $phaseNumber - 1,
                'title' => "Phase {$phaseNumber}",
                'state' => $phaseNumber < 3 ? 'done' : ($phaseNumber === 3 ? 'active' : 'upcoming'),
                'week_start' => ($phaseNumber - 1) * 2 + 1,
                'week_end' => $phaseNumber * 2,
            ]);
        }

        $shortProtocol = Protocol::query()->create([
            'horse_id' => $horse->id,
            'protocol_template_id' => $protocolTemplate->id,
            'title' => 'Two phases',
            'total_weeks' => 6,
            'current_week' => 2,
            'status' => 'active',
        ]);
        foreach (range(1, 2) as $phaseNumber) {
            ProtocolPhase::query()->create([
                'protocol_id' => $shortProtocol->id,
                'protocol_template_phase_id' => $phaseDefinitions[$phaseNumber - 1]->id,
                'order' => $phaseNumber - 1,
                'title' => "Phase {$phaseNumber}",
                'state' => $phaseNumber === 1 ? 'active' : 'upcoming',
                'week_start' => ($phaseNumber - 1) * 2 + 1,
                'week_end' => $phaseNumber * 2,
            ]);
        }

        $preparedProtocol = Protocol::query()->create([
            'horse_id' => $horse->id,
            'protocol_template_id' => $protocolTemplate->id,
            'title' => 'Preparation plus three phases',
            'total_weeks' => 8,
            'current_week' => 2,
            'status' => 'active',
        ]);
        foreach (['Voorbereiding', 'Phase 1', 'Phase 2', 'Phase 3'] as $order => $title) {
            ProtocolPhase::query()->create([
                'protocol_id' => $preparedProtocol->id,
                'protocol_template_phase_id' => $phaseDefinitions[$order]->id,
                'order' => $order,
                'title' => $title,
                'state' => $order === 1 ? 'active' : ($order === 0 ? 'done' : 'upcoming'),
                'week_start' => $order === 0 ? 0 : (($order - 1) * 2 + 1),
                'week_end' => $order === 0 ? 0 : $order * 2,
            ]);
        }

        $migration = require database_path('migrations/2026_08_19_000001_normalize_protocols_to_three_phases.php');
        $migration->up();

        $longProtocol->load('phases');
        $thirdPhase = $longProtocol->phases[2];

        $this->assertCount(3, $longProtocol->phases);
        $this->assertSame(8, $thirdPhase->week_end);
        $this->assertSame(3, $shortProtocol->phases()->count());

        $preparedProtocol->load('phases');
        $this->assertCount(3, $preparedProtocol->phases);
        $this->assertSame('Phase 1', $preparedProtocol->phases[0]->title);
    }
}
