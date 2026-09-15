<?php

namespace Tests\Feature;

use Tests\TestCase;

class PowerSyncProtocolPublicationTest extends TestCase
{
    public function test_every_customer_protocol_stream_excludes_drafts(): void
    {
        $rules = file_get_contents(base_path('powersync/sync_rules.yaml'));
        $protocolSection = str($rules)
            ->after('# Protocol data for visible horses.')
            ->before('# Observations for visible horses.')
            ->toString();

        $this->assertStringContainsString('SELECT * FROM protocol_supplement_intakes', $protocolSection);
        $this->assertStringContainsString('SELECT * FROM protocol_voeding_adviezen', $protocolSection);
        $this->assertStringContainsString('SELECT * FROM protocol_management_adviezen', $protocolSection);
        $this->assertStringContainsString('SELECT * FROM protocol_beweging_adviezen', $protocolSection);
        $this->assertSame(8, substr_count($protocolSection, 'SELECT * FROM'));
        $this->assertStringNotContainsString('SELECT * FROM protocol_phase_supplements', $protocolSection);
        $this->assertStringNotContainsString('SELECT * FROM protocol_phase_supplement_weeks', $protocolSection);
        $this->assertStringNotContainsString('SELECT * FROM protocol_phases', $protocolSection);
        $this->assertStringContainsString('SELECT id, protocol_id, "order", title, state, week_start', $protocolSection);
        $this->assertSame(9, substr_count($protocolSection, 'published_at IS NOT NULL'));
    }
}
