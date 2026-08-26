<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProtocolTasksRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_protocol_task_storage_is_removed(): void
    {
        $this->assertFalse(Schema::hasTable('protocol_tasks'));
        $this->assertFalse(Schema::hasTable('protocol_task_completions'));
        $this->assertFalse(Schema::hasColumn('observations', 'protocol_task_id'));
    }
}
