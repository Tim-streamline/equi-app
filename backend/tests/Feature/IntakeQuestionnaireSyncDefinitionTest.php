<?php

namespace Tests\Feature;

use Tests\TestCase;

class IntakeQuestionnaireSyncDefinitionTest extends TestCase
{
    public function test_active_backend_questionnaire_definition_is_part_of_the_mobile_sync_contract(): void
    {
        $rules = file_get_contents(base_path('powersync/sync_rules.yaml'));

        $this->assertStringContainsString('SELECT * FROM intake_questionnaires WHERE active = TRUE', $rules);
        $this->assertStringContainsString('SELECT * FROM intake_sections WHERE active = TRUE', $rules);
        $this->assertStringContainsString('SELECT * FROM intake_fields WHERE active = TRUE', $rules);
    }
}
