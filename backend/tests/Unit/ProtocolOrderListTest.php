<?php

namespace Tests\Unit;

use App\Models\Protocol;
use App\Models\ProtocolPhase;
use App\Models\ProtocolPhaseSupplement;
use App\Models\ProtocolPhaseSupplementWeek;
use App\Models\Supplement;
use App\Support\ProtocolOrderList;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class ProtocolOrderListTest extends TestCase
{
    private function protocol(array $rows): Protocol
    {
        $phases = [];
        foreach ($rows as $index => $row) {
            [$dose, $frequency, $weeks] = $row;
            $phase = new ProtocolPhase(['title' => 'Fase '.$index]);
            $phase->id = 'phase-'.$index;
            $item = new ProtocolPhaseSupplement(['name' => 'Heemstwortel', 'dosage' => $dose, 'aantal_per_week' => $frequency]);
            $item->id = 'item-'.$index;
            $item->supplement_id = 'catalog-'.$index;
            $catalog = new Supplement(['product_url' => 'https://example.com/heemst']);
            $catalog->id = $item->supplement_id;
            $item->setRelation('supplement', $catalog);
            $item->setRelation('weeks', new Collection(array_map(fn ($week) => new ProtocolPhaseSupplementWeek([
                'protocol_phase_week_id' => 'week-'.$week,
            ]), $weeks)));
            $phase->setRelation('supplements', new Collection([$item]));
            $phases[] = $phase;
        }
        $protocol = new Protocol;
        $protocol->setRelation('phases', new Collection($phases));

        return $protocol;
    }

    public function test_six_weeks_at_fifteen_grams_daily_is_630_grams_and_changes_with_schedule_and_dose(): void
    {
        $protocol = $this->protocol([['15 g', 4, range(1, 6)]]);
        $service = new ProtocolOrderList;
        $item = $service->forProtocol($protocol)[0];
        $this->assertEquals(630, $item['quantity']);
        $this->assertSame('630 g', $item['quantityLabel']);
        $this->assertSame(42, $item['components'][0]['administrations']);
        $this->assertSame(['item-0'], $item['selectionIds']);
        $this->assertSame('https://example.com/heemst', $item['purchaseOptions'][0]['url']);
        $this->assertArrayNotHasKey('instructions', $item);
        $this->assertArrayNotHasKey('description', $item);
        $selection = $protocol->phases[0]->supplements[0];
        $selection->dosage = '10 g';
        $selection->aantal_per_week = 14;
        $this->assertEquals(420, $service->forProtocol($protocol)[0]['quantity']);
        $selection->setRelation('weeks', new Collection($selection->weeks->take(4)->all()));
        $this->assertEquals(280, $service->forProtocol($protocol)[0]['quantity']);
        $selection->supplement->product_url = 'https://example.org/new-product';
        $this->assertSame('https://example.org/new-product', $service->forProtocol($protocol)[0]['purchaseOptions'][0]['url']);
    }

    public function test_two_daily_full_doses_double_the_quantity_independently_of_the_weekly_minimum(): void
    {
        $service = new ProtocolOrderList;
        foreach (['Psyliumzaad (bio)', 'Gekookt (bio) lijnzaad', 'Schüsslerzout nr. 10', 'Schüsslerzout nr. 27'] as $name) {
            $protocol = $this->protocol([['15 g', 4, range(1, 6)]]);
            $selection = $protocol->phases[0]->supplements[0];
            $selection->name = $name;
            $selection->supplement->administrations_per_day = 2;
            $item = $service->forProtocol($protocol)[0];
            $this->assertSame('1,26 kg', $item['quantityLabel']);
            $this->assertSame(2, $item['components'][0]['administrationsPerDay']);
            $this->assertSame(84, $item['components'][0]['administrations']);
            $this->assertSame(7, $item['components'][0]['daysPerWeek']);
            // An administrator can change this without editing a product-specific formula.
            $selection->supplement->administrations_per_day = 1;
            $this->assertSame('630 g', $service->forProtocol($protocol)[0]['quantityLabel']);
            // The snapshot retains twice-daily use if the catalogue entry is later removed.
            $selection->administrations_per_day = 2;
            $selection->setRelation('supplement', null);
            $this->assertSame('1,26 kg', $service->forProtocol($protocol)[0]['quantityLabel']);
        }
    }

    public function test_daily_for_eight_weeks_is_1120_grams_and_formats_as_kilograms(): void
    {
        $result = (new ProtocolOrderList)->forProtocol($this->protocol([['20 g', 5, range(1, 8)]]))[0];
        $this->assertEquals(1120, $result['quantity']);
        $this->assertSame('g', $result['unit']);
        $this->assertSame('1,12 kg', $result['quantityLabel']);
    }

    public function test_same_product_is_summed_across_phases_and_units_are_converted_without_counting_unselected_weeks(): void
    {
        $result = (new ProtocolOrderList)->forProtocol($this->protocol([
            ['0,01 kg', 7, [1, 3, 5, 5]], ['10 g', 7, [2, 4]],
        ]));
        $this->assertCount(1, $result);
        $this->assertEquals(350, $result[0]['quantity']);
        $this->assertCount(2, $result[0]['components']);
        $this->assertCount(2, $result[0]['selectionIds']);
        $this->assertCount(2, $result[0]['catalogIds']);
    }

    public function test_volume_counts_and_ambiguous_doses_are_not_mistaken_for_grams(): void
    {
        $service = new ProtocolOrderList;
        $this->assertSame('1,12 l', $service->forProtocol($this->protocol([['20 ml', 5, range(1, 8)]]))[0]['quantityLabel']);
        $this->assertSame('84 capsules', $service->forProtocol($this->protocol([['2 capsules', 7, range(1, 6)]]))[0]['quantityLabel']);
        foreach (['10–20 g', 'een handje', '15 g per dag', '', null] as $dose) {
            $item = $service->forProtocol($this->protocol([[$dose, 7, [1]]]))[0];
            $this->assertFalse($item['calculationComplete']);
            $this->assertNull($item['quantity']);
            $this->assertNull($item['quantityLabel']);
        }
        foreach ([null, 0, 4, 5, 7, 14] as $minimum) {
            $item = $service->forProtocol($this->protocol([['15 g', $minimum, range(1, 6)]]))[0];
            $this->assertEquals(630, $item['quantity']);
            $this->assertSame(7, $item['components'][0]['administrationsPerWeek']);
            $this->assertSame('daily_7_per_week', $item['calculationBasis']);
        }
        $this->assertSame([], $service->forProtocol($this->protocol([['15 g', 7, []]])));
    }

    public function test_unsafe_purchase_urls_are_never_exposed(): void
    {
        $protocol = $this->protocol([['15 g', 7, [1]]]);
        $protocol->phases[0]->supplements[0]->supplement->product_url = 'javascript:alert(1)';
        $this->assertSame([], (new ProtocolOrderList)->forProtocol($protocol)[0]['purchaseOptions']);
    }
}
