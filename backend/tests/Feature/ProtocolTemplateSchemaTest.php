<?php

namespace Tests\Feature;

use App\Models\ProtocolTemplate;
use App\Models\ProtocolTemplatePhase;
use App\Models\ProtocolTemplatePhaseWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProtocolTemplateSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_protocol_template_models_and_database_names_are_canonical(): void
    {
        $this->assertTrue(class_exists(ProtocolTemplate::class));
        $this->assertTrue(class_exists(ProtocolTemplatePhase::class));
        $this->assertTrue(class_exists(ProtocolTemplatePhaseWeek::class));
        $this->assertFalse(class_exists('App\\Models\\ProtocolType'));
        $this->assertFalse(class_exists('App\\Models\\ProtocolTypePhase'));
        $this->assertFalse(class_exists('App\\Models\\ProtocolTypePhaseWeek'));

        $this->assertTrue(Schema::hasTable('protocol_templates'));
        $this->assertTrue(Schema::hasTable('protocol_template_phases'));
        $this->assertTrue(Schema::hasTable('protocol_template_phase_weeks'));
        $this->assertFalse(Schema::hasTable('protocol_types'));
        $this->assertFalse(Schema::hasTable('protocol_type_phases'));
        $this->assertFalse(Schema::hasTable('protocol_type_phase_weeks'));

        $this->assertTrue(Schema::hasColumns('protocols', [
            'protocol_template_id',
            'protocol_template_name',
        ]));
        $this->assertTrue(Schema::hasColumn('protocol_phases', 'protocol_template_phase_id'));
        $this->assertTrue(Schema::hasColumn('protocol_template_phases', 'start_after_previous_phase_weeks'));
        $this->assertTrue(Schema::hasColumn('protocol_phases', 'start_after_previous_phase_weeks'));
        $this->assertTrue(Schema::hasColumn('protocol_phase_weeks', 'protocol_template_phase_week_id'));
        $this->assertTrue(Schema::hasColumn('supplements', 'protocol_template_phase_id'));
        $this->assertTrue(Schema::hasColumn('supplement_weeks', 'protocol_template_phase_week_id'));

        $this->assertFalse(Schema::hasColumn('protocols', 'protocol_type_id'));
        $this->assertFalse(Schema::hasColumn('protocols', 'protocol_type_name'));
        $this->assertFalse(Schema::hasColumn('protocol_phases', 'protocol_type_phase_id'));
        $this->assertFalse(Schema::hasColumn('protocol_phase_weeks', 'protocol_type_phase_week_id'));
        $this->assertFalse(Schema::hasColumn('supplements', 'protocol_type_phase_id'));
        $this->assertFalse(Schema::hasColumn('supplement_weeks', 'protocol_type_phase_week_id'));

        $this->assertSame([], DB::select(
            "select conname from pg_constraint where conname like '%protocol_type%'",
        ));
        $this->assertSame([], DB::select(
            "select indexname from pg_indexes where schemaname = current_schema() and indexname like '%protocol_type%'",
        ));
    }
}
