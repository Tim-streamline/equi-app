<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\IntakeQuestionnaireSnapshot;
use App\Support\IntakeReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntakeReviewFlagsRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_removing_flags_preserves_definitions_answers_and_critical_safety(): void
    {
        $q = IntakeQuestionnaire::create(['slug' => 'protocol-intake', 'name' => 'Intake', 'active' => true]);
        $section = $q->sections()->create(['key' => 'health', 'title' => 'Health', 'active' => true]);
        $field = $section->fields()->create(['key' => 'condition', 'label' => 'Condition', 'type' => 'multi', 'active' => true,
            'options' => ['danger', 'none'], 'critical_if' => ['danger'], 'option_effects' => ['none' => ['exclusive_selection' => true]]]);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Test horse']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $answer = $booking->answers()->create(['section_id' => 'health', 'field_id' => 'condition', 'value' => '["danger"]']);
        $original = $field->fresh()->getAttributes();
        $originalAnswer = $answer->fresh()->getAttributes();

        $migration = require database_path('migrations/2026_11_01_000003_remove_intake_review_flags.php');
        $migration->down();
        DB::table('intake_fields')->where('id', $field->id)->update(['flag_if' => '"any"']);
        $migration->up();
        $this->assertFalse(Schema::hasColumn('intake_fields', 'flag_if'));
        $this->assertEquals($original, $field->fresh()->getAttributes());
        $this->assertSame($originalAnswer, $answer->fresh()->getAttributes());

        $review = app(IntakeReview::class)->forBooking($booking);
        $row = $review['sections'][0]['rows'][0];
        $this->assertTrue($review['blocked']);
        $this->assertTrue($row['critical']);
        $this->assertSame(['danger'], $row['critical_options']);
        $this->assertArrayNotHasKey('flagged', $row);
        $this->assertArrayNotHasKey('flagged_options', $row);
        $this->assertArrayNotHasKey('flags', $review['counts']);
        $this->assertArrayNotHasKey('flags', $review['sections'][0]);
        $this->assertNotContains('flag_if', (new IntakeField)->getFillable());

        $snapshots = app(IntakeQuestionnaireSnapshot::class);
        $source = $snapshots->export();
        $this->assertArrayNotHasKey('flag_if', $source['sections'][0]['fields'][0]);
        // Existing definition backups remain usable; obsolete settings are ignored.
        $source['sections'][0]['fields'][0]['flag_if'] = ['danger'];
        $snapshots->validate($source);
        $this->assertEmpty($snapshots->sync($source)['changes']);
    }
}
