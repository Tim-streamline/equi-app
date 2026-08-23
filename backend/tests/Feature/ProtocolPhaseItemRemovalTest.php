<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProtocolPhaseItemRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_protocol_phase_summary_storage_has_been_removed(): void
    {
        $this->assertFalse(Schema::hasTable('protocol_phase_items'));
        $this->assertFileDoesNotExist(app_path('Models/ProtocolPhaseItem.php'));
    }
}
