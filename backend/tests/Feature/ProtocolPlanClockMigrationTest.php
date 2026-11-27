<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** 2026_11_27_000001_add_plan_clock_to_protocols: earlier pauses from the audit log. */
class ProtocolPlanClockMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function pausedPeriods(object $protocol, string $today): array
    {
        $migration = require database_path('migrations/2026_11_27_000001_add_plan_clock_to_protocols.php');
        $method = new \ReflectionMethod($migration, 'pausedPeriods');

        return $method->invoke($migration, $protocol, 'Europe/Amsterdam', $today);
    }

    private function statusChange(string $protocolId, string $from, string $to, string $at): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'action' => 'updated', 'target_type' => 'Protocol', 'target_id' => $protocolId,
            'before' => json_encode(['status' => $from]), 'after' => json_encode(['status' => $to]),
            'created_at' => CarbonImmutable::parse($at, 'Europe/Amsterdam')->utc()]);
    }

    public function test_earlier_pauses_come_from_the_audit_log(): void
    {
        $horse = Horse::create(['owner_id' => User::factory()->create()->id, 'name' => 'Bella', 'status' => 'active']);
        $active = (object) ['id' => (string) Str::uuid(), 'horse_id' => $horse->id, 'status' => 'active'];
        // Paused on 5 September in the evening, resumed on 12 September: 6 days skipped.
        $this->statusChange($active->id, 'active', 'paused', '2026-09-05 22:30');
        $this->statusChange($active->id, 'paused', 'active', '2026-09-12 08:00');
        // Paused in the evening and resumed the next morning: nothing skipped.
        $this->statusChange($active->id, 'active', 'paused', '2026-10-01 21:00');
        $this->statusChange($active->id, 'paused', 'active', '2026-10-02 07:00');
        $this->assertSame([['after' => '2026-09-05', 'before' => '2026-09-12']], $this->pausedPeriods($active, '2026-11-27'));

        // Paused now: open from the recorded pause; without a record, from today.
        $paused = (object) ['id' => (string) Str::uuid(), 'horse_id' => $horse->id, 'status' => 'paused'];
        $this->statusChange($paused->id, 'active', 'paused', '2026-11-20 10:00');
        $this->assertSame([['after' => '2026-11-20', 'before' => null]], $this->pausedPeriods($paused, '2026-11-27'));
        $unrecorded = (object) ['id' => (string) Str::uuid(), 'horse_id' => $horse->id, 'status' => 'paused'];
        $this->assertSame([['after' => '2026-11-27', 'before' => null]], $this->pausedPeriods($unrecorded, '2026-11-27'));
    }
}
