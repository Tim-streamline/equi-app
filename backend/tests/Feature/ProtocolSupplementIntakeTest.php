<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\ProtocolSupplementIntake;
use App\Models\ProtocolTemplate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtocolSupplementIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_one_daily_intake_with_the_configured_dosage_snapshot(): void
    {
        $owner = User::factory()->create();
        $horse = Horse::query()->create([
            'owner_id' => $owner->id,
            'name' => 'Nova',
            'status' => 'active',
        ]);
        $template = ProtocolTemplate::query()->create(['name' => 'Darmprotocol']);
        $templatePhase = $template->phases()->create([
            'order' => 0,
            'name' => 'Herstel',
            'required' => true,
        ]);
        $protocol = $horse->protocols()->create([
            'protocol_template_id' => $template->id,
            'protocol_template_name' => $template->name,
            'title' => 'Darmprotocol',
            'status' => 'active',
            'published_at' => now(),
        ]);
        $phase = $protocol->phases()->create([
            'protocol_template_phase_id' => $templatePhase->id,
            'order' => 0,
            'title' => 'Herstel',
            'state' => 'active',
        ]);
        $supplement = $phase->supplements()->create([
            'name' => 'Gekookt (bio) lijnzaad',
            'dosage' => '90 g',
        ]);

        $intake = ProtocolSupplementIntake::query()->create([
            'protocol_phase_supplement_id' => $supplement->id,
            'horse_id' => $horse->id,
            'date' => '2026-08-23',
            'dosage' => $supplement->dosage,
            'done' => true,
            'taken_at' => '2026-08-23 08:30:00',
        ]);

        $this->assertTrue($intake->done);
        $this->assertSame('90 g', $intake->dosage);
        $this->assertSame('2026-08-23', $intake->date->toDateString());
        $this->assertTrue($supplement->intakes()->whereKey($intake->id)->exists());
        $this->assertTrue($horse->supplementIntakes()->whereKey($intake->id)->exists());

        $this->expectException(QueryException::class);
        ProtocolSupplementIntake::query()->create([
            'protocol_phase_supplement_id' => $supplement->id,
            'horse_id' => $horse->id,
            'date' => '2026-08-23',
            'dosage' => '90 g',
            'done' => true,
        ]);
    }
}
