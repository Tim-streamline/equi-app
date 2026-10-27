<?php

namespace Tests\Feature;

use App\Models\IntakeField;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeMineralFollowupRepairTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_27_000003_repair_mineral_other_followup.php'))->up();
    }

    public function test_a_broken_rule_is_repaired_without_overwriting_admin_wording(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $field = IntakeField::where('key', 'mineralen-toegang-anders')->sole();
        $field->update(['label' => 'Welke andere mineralen?', 'required' => false, 'optional' => true, 'show_if' => ['mineralen-toegang' => ['bestaat niet']]]);

        $this->migrate();

        $field->refresh();
        $this->assertSame('Welke andere mineralen?', $field->label);
        $this->assertTrue($field->optional);
        $this->assertContains('anders', array_values($field->show_if)[0]);
    }

    public function test_a_working_rule_and_custom_settings_are_left_alone(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $field = IntakeField::where('key', 'mineralen-toegang-anders')->sole();
        $field->update(['label' => 'Eigen tekst', 'show_if' => ['voer.mineralen-toegang' => ['anders']]]);
        $before = $field->fresh()->getAttributes();

        $this->migrate();

        $this->assertSame($before, $field->fresh()->getAttributes());
    }

    public function test_a_missing_follow_up_is_created_directly_after_its_question(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $parent = IntakeField::where('key', 'mineralen-toegang')->sole();
        IntakeField::where('key', 'mineralen-toegang-anders')->delete();

        $this->migrate();
        $this->migrate();

        $field = IntakeField::where('key', 'mineralen-toegang-anders')->sole();
        $this->assertSame($parent->order + 1, $field->order);
        $this->assertSame('Licht je antwoord toe.', $field->label);
    }
}
