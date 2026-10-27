<?php

namespace Tests\Feature;

use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EquiBoostNamingTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_intake_texts_are_renamed_from_plus_to_equiboost(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $field = IntakeField::where('key', 'hoefbevangenheid')->firstOrFail();
        $field->update(['hint' => 'Het persoonlijke Plus-protocol kan pas worden opgesteld.']);
        $q = IntakeQuestionnaire::where('slug', 'protocol-intake')->firstOrFail();
        DB::table('intake_questionnaires')->where('id', $q->id)->update(['safety_config' => json_encode(['blocked' => 'Plus-protocol opstellen is geblokkeerd.'])]);

        $migration = require database_path('migrations/2026_10_27_000008_rename_plus_to_equiboost_in_intake_texts.php');
        $migration->up();
        $migration->up();

        $this->assertSame('Het persoonlijke EquiBoost-protocol kan pas worden opgesteld.', $field->fresh()->hint);
        $this->assertStringContainsString('EquiBoost-protocol opstellen', DB::table('intake_questionnaires')->where('id', $q->id)->value('safety_config'));
        $this->assertSame(0, IntakeField::where('hint', 'like', '%Plus-protocol%')->count());
    }
}
