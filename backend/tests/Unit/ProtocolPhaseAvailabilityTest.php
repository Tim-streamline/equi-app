<?php

namespace Tests\Unit;

use App\Models\Protocol;
use App\Models\ProtocolPhase;
use App\Models\ProtocolPhaseWeek;
use App\Support\ProtocolPhaseAvailability;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ProtocolPhaseAvailabilityTest extends TestCase
{
    public function test_exact_seven_calendar_day_boundary_including_dst_and_overlap(): void
    {
        $protocol = new Protocol(['started_at' => '2026-03-22']);
        $phase = new ProtocolPhase(['week_start' => 3, 'week_end' => 4]);
        $phase->setRelation('weeks', collect());
        $service = new ProtocolPhaseAvailability;
        foreach ([
            '2026-03-28 23:59:59' => 'locked',
            '2026-03-29 00:00:00' => 'preview',
            '2026-04-04 23:59:59' => 'preview',
            '2026-04-05 00:00:00' => 'active',
            '2026-04-18 23:59:59' => 'active',
            '2026-04-19 00:00:00' => 'done',
        ] as $date => $expected) {
            $result = $service->forPhase($protocol, $phase, CarbonImmutable::parse($date, 'Europe/Amsterdam'));
            $this->assertSame($expected, $result['state'], $date);
            $this->assertSame($expected !== 'locked', $result['accessible']);
        }
        $overlap = new ProtocolPhase(['week_start' => 2, 'week_end' => 3]);
        $overlap->setRelation('weeks', collect());
        $now = CarbonImmutable::parse('2026-04-05', 'Europe/Amsterdam');
        $this->assertSame('active', $service->forPhase($protocol, $overlap, $now)['state']);
        $this->assertSame('active', $service->forPhase($protocol, $phase, $now)['state']);
        // The same instant is still the previous calendar day in Los Angeles.
        $this->assertSame('locked', $service->forPhase($protocol, $phase, CarbonImmutable::parse('2026-03-29T00:00:00+01:00')->setTimezone('America/Los_Angeles'))['state']);
    }

    public function test_missing_start_is_locked_and_actual_week_schedule_takes_precedence(): void
    {
        $phase = new ProtocolPhase(['week_start' => 1, 'week_end' => 2]);
        $phase->setRelation('weeks', collect([new ProtocolPhaseWeek(['protocol_week_number' => 8]), new ProtocolPhaseWeek(['protocol_week_number' => 10])]));
        $service = new ProtocolPhaseAvailability;
        $now = CarbonImmutable::parse('2026-09-16');
        $this->assertSame('locked', $service->forPhase(new Protocol, $phase, $now)['state']);
        $result = $service->forPhase(new Protocol(['started_at' => '2026-09-01']), $phase, $now);
        $this->assertSame(8, $result['weekStart']);
        $this->assertSame(10, $result['weekEnd']);
        $this->assertSame('locked', $result['state']);
    }
}
