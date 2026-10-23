<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\IntakeProgress;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeRoughageGapsTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): IntakeBooking
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Roughage tests']);
        return IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
    }

    public function test_hidden_followups_never_block_and_variable_gaps_require_both_followups(): void
    {
        $booking = $this->booking();
        $main = 'hooi-zonder-ruwvoer-langste';
        $answer = $booking->answers()->create(['section_id' => 'voer', 'field_id' => $main, 'value' => json_encode('Wisselend')]);
        $missing = fn () => collect(app(IntakeProgress::class)->forBooking($booking->load('answers'))['missing'])->where('section', 'voer')->pluck('field')->filter(fn ($key) => str_starts_with($key, 'hooi-zonder-ruwvoer'))->values()->all();
        $this->assertSame(['hooi-zonder-ruwvoer-toelichting', 'hooi-zonder-ruwvoer-frequentie'], $missing());
        $answer->update(['value' => json_encode('2 tot 4 uur')]);
        $this->assertSame(['hooi-zonder-ruwvoer-frequentie'], $missing());
        foreach (['Nooit, er is altijd ruwvoer beschikbaar', 'Weet ik niet'] as $value) {
            $answer->update(['value' => json_encode($value)]);
            $this->assertSame([], $missing());
        }
    }

    public function test_migration_preserves_originals_and_maps_only_the_longest_duration_without_inventing_frequency(): void
    {
        $booking = $this->booking();
        $section = IntakeQuestionnaire::where('slug', 'protocol-intake')->firstOrFail()->sections()->where('key', 'voer')->firstOrFail();
        $section->fields()->where('key', 'like', 'hooi-zonder-ruwvoer-%')->delete();
        foreach (['hooi-pauze-incl-nacht' => 'Ja, regelmatig', 'hooi-pauze-uren' => '2–4 uur', 'hooi-pauze-langste' => 7] as $key => $value) {
            $section->fields()->create(['key' => $key, 'label' => $key, 'type' => is_int($value) ? 'number' : 'radio', 'order' => 100, 'active' => true]);
            $booking->answers()->create(['section_id' => 'voer', 'field_id' => $key, 'value' => json_encode($value)]);
        }
        $before = $booking->answers()->pluck('value', 'id')->all();
        $migration = require database_path('migrations/2026_10_21_000012_merge_roughage_gap_questions.php');
        $migration->up();
        foreach ($before as $id => $value) $this->assertSame($value, $booking->answers()->findOrFail($id)->value);
        $new = $booking->answers()->where('field_id', 'hooi-zonder-ruwvoer-langste')->firstOrFail();
        $this->assertSame('Meer dan 6 uur', json_decode($new->value));
        $this->assertFalse($booking->answers()->where('field_id', 'hooi-zonder-ruwvoer-frequentie')->exists());
        $this->assertSame(0, $section->fields()->where('key', 'like', 'hooi-pauze-%')->where('active', true)->count());
        $new->update(['value' => json_encode('Weet ik niet')]);
        $migration->up();
        $this->assertSame('Weet ik niet', json_decode($new->fresh()->value));
    }
}
