<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\IntakeQuestionnaireSnapshot;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IntakeOptionEffectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_preserves_each_questionnaires_selection_rules_without_changing_answers(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $q = IntakeQuestionnaire::where('slug', 'protocol-intake')->sole();
        $section = $q->sections()->firstOrFail();
        $field = $section->fields()->create(['key' => 'effects-test', 'label' => 'Test', 'type' => 'multi', 'options' => ['Custom exclusive', 'Geen', 'Other']]);
        $q->update(['none_options' => [' custom EXCLUSIVE ']]);
        $other = IntakeQuestionnaire::create(['slug' => 'other', 'name' => 'Other', 'none_options' => ['geen']]);
        $otherSection = $other->sections()->create(['key' => 'other', 'title' => 'Other']);
        $otherField = $otherSection->fields()->create(['key' => 'other', 'label' => 'Other', 'type' => 'multi', 'options' => $field->options]);
        $radio = $section->fields()->create(['key' => 'effects-radio', 'label' => 'Radio', 'type' => 'radio', 'options' => $field->options]);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Effects test']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $booking->answers()->create(['section_id' => $section->key, 'field_id' => $field->key, 'value' => json_encode(['Geen', 'Other'])]);
        $beforeAnswers = DB::table('intake_answers')->get()->toJson();
        $migration = require database_path('migrations/2026_11_01_000001_add_intake_option_effects.php');
        $migration->down();
        $migration->up();

        $this->assertSame(['Custom exclusive' => ['exclusive_selection' => true]], $field->fresh()->option_effects);
        $this->assertSame(['Geen' => ['exclusive_selection' => true]], $otherField->fresh()->option_effects);
        $this->assertNull($radio->fresh()->option_effects);
        $this->assertSame([' custom EXCLUSIVE '], $q->fresh()->none_options);
        $this->assertSame($beforeAnswers, DB::table('intake_answers')->get()->toJson());
    }

    public function test_snapshot_round_trip_preserves_effects_and_older_snapshots_can_omit_them(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $snapshots = app(IntakeQuestionnaireSnapshot::class);
        $snapshot = $snapshots->export();
        $snapshots->validate($snapshot);
        $this->assertEmpty($snapshots->sync($snapshot)['changes']);
        foreach ($snapshot['sections'] as &$section) {
            foreach ($section['fields'] as &$field) {
                unset($field['option_effects']);
            }
            unset($field);
        }
        unset($section);
        $snapshots->validate($snapshot);
        $this->assertEmpty($snapshots->sync($snapshot)['changes']);

        $snapshot['sections'][0]['fields'][0]['option_effects'] = ['Unknown' => ['exclusive_selection' => true]];
        $this->expectException(ValidationException::class);
        $snapshots->validate($snapshot);
    }
}
