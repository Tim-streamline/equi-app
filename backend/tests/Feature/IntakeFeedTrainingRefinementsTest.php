<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\IntakeAnswersPdf;
use App\Support\IntakeReview;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeFeedTrainingRefinementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_preserves_answers_and_admin_edits_and_is_idempotent(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $q = IntakeQuestionnaire::where('slug', 'protocol-intake')->firstOrFail();
        $patch = json_decode(file_get_contents(database_path('data/intake-refinements-2026-09-30.json')), true);
        foreach ($patch['sections'] as $key => $changes) {
            $section = $q->sections()->where('key', $key)->firstOrFail();
            foreach ($changes['add'] as $addition) {
                $section->fields()->where('key', $addition['field']['id'])->delete();
            }
            foreach ($changes['retire'] as $id) {
                $section->fields()->create(['key' => $id, 'type' => 'text', 'label' => 'Oude vraag', 'order' => 500, 'active' => true]);
            }
        }
        $feed = $q->sections()->where('key', 'voer')->firstOrFail();
        $feed->fields()->where('key', 'analyse-suiker')->update(['optional' => true, 'required' => false]);
        $feed->fields()->where('key', 'voordroog-type')->update(['options' => ['Hooi in plastic verpakt']]);
        $custom = $feed->fields()->create(['key' => 'custom', 'label' => 'Eigen vraag', 'type' => 'text', 'order' => 600, 'active' => true]);
        $untouched = $feed->fields()->where('key', 'hooi-herkomst')->firstOrFail();
        $untouched->update(['label' => 'Eigen herkomstvraag']);
        $training = $q->sections()->where('key', 'gedrag')->firstOrFail();
        $training->fields()->where('key', 'discipline')->update(['order' => 0, 'options' => ['Eigen discipline']]);
        $booking = $this->booking();
        $answer = $booking->answers()->create(['section_id' => 'voer', 'field_id' => 'huidige-bijvoeding', 'value' => json_encode([['product' => 'Bestaand voer']])]);
        $oldWater = $booking->answers()->create(['section_id' => 'huisvesting', 'field_id' => 'paddock-water', 'value' => json_encode('Ja')]);
        $migration = require database_path('migrations/2026_09_30_000000_refine_feed_and_training_intake.php');
        $migration->up();
        $snapshot = $q->sections()->with('fields')->get()->toJson();
        $this->travel(2)->seconds();
        $migration->up();
        $this->assertSame($snapshot, $q->sections()->with('fields')->get()->toJson());
        $this->assertSame($answer->value, $answer->fresh()->value);
        $this->assertSame($oldWater->value, $oldWater->fresh()->value);
        $this->assertTrue($custom->fresh()->active);
        $this->assertSame('Eigen herkomstvraag', $untouched->fresh()->label);
        $this->assertSame(['Eigen discipline'], $training->fields()->where('key', 'discipline')->firstOrFail()->options);
        foreach ($patch['sections'] as $key => $changes) {
            $section = $q->sections()->where('key', $key)->firstOrFail();
            foreach ($changes['retire'] as $id) {
                $this->assertFalse($section->fields()->where('key', $id)->firstOrFail()->active);
            }
            foreach ($changes['add'] as $addition) {
                $this->assertSame(1, $section->fields()->where('key', $addition['field']['id'])->count());
            }
        }
        $this->assertFalse($feed->fields()->where('key', 'analyse-suiker')->firstOrFail()->optional);
        $this->assertSame(['Hooi', 'Voordroog', 'Kuilvoer', 'Anders, namelijk', 'Weet ik niet'], $feed->fields()->where('key', 'voordroog-type')->firstOrFail()->options);
        $keys = $training->fields()->where('active', true)->orderBy('order')->pluck('key')->all();
        $this->assertSame('discipline', $keys[array_search('training-vormen-anders', $keys) + 1]);
        $expected = json_decode(file_get_contents(database_path('data/intake-questionnaire.json')), true);
        $trainingDefinition = collect($expected['sections'])->firstWhere('id', 'gedrag');
        $this->assertSame(array_column($trainingDefinition['fields'], 'id'), $keys);
        $feedKeys = $feed->fields()->where('active', true)->orderBy('order')->pluck('key')->all();
        $this->assertSame('huidige-bijvoeding', $feedKeys[array_search('bijvoeding-nu', $feedKeys) + 1]);
    }

    public function test_review_and_pdf_exclude_hidden_answers_but_preserve_them_for_later(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $booking = $this->booking();
        $answers = [
            'voer' => ['bijvoeding-nu' => 'Nee', 'huidige-bijvoeding' => [['product' => 'HIDDEN-FEED']],
                'supplementen-nu' => 'Nee', 'huidig-extra' => [['product' => 'HIDDEN-SUPPLEMENT']],
                'ruwvoer-geanalyseerd' => 'Nee', 'analyse-suiker' => 'HIDDEN-SUGAR'],
            'klacht' => ['bloedonderzoek-gedaan' => 'Nee', 'bloedonderzoek' => ['attachment:old']],
            'huisvesting' => ['paddock-water' => 'HIDDEN-WATER'],
            'gedrag' => ['training-freq' => '0×', 'training-duur' => 'HIDDEN-DURATION',
                'training-knelpunten' => 'HIDDEN-PROBLEM', 'training-veranderd' => 'Nee',
                'training-veranderd-details' => 'HIDDEN-CHANGE', 'stress-symptomen' => ['Geen van bovenstaande']],
        ];
        foreach ($answers as $section => $fields) {
            foreach ($fields as $field => $value) {
                $booking->answers()->create(['section_id' => $section, 'field_id' => $field, 'value' => json_encode($value)]);
            }
        }
        $review = app(IntakeReview::class)->forBooking($booking->fresh());
        $rows = collect($review['sections'])->flatMap(fn ($section) => $section['rows']);
        foreach (['huidige-bijvoeding', 'huidig-extra', 'analyse-suiker', 'bloedonderzoek', 'paddock-water', 'training-duur', 'training-knelpunten', 'training-veranderd-details'] as $id) {
            $this->assertFalse($rows->contains('key', $id), $id);
        }
        $this->assertFalse($rows->firstWhere('key', 'stress-symptomen')['flagged']);
        $html = app(IntakeAnswersPdf::class)->html($booking->fresh());
        $this->assertStringNotContainsString('HIDDEN-', $html);
        $booking->answers()->where('section_id', 'voer')->where('field_id', 'bijvoeding-nu')->update(['value' => json_encode('Ja')]);
        $review = app(IntakeReview::class)->forBooking($booking->fresh());
        $rows = collect($review['sections'])->flatMap(fn ($section) => $section['rows']);
        $this->assertSame([['product' => 'HIDDEN-FEED']], $rows->firstWhere('key', 'huidige-bijvoeding')['value']);
    }

    private function booking(): IntakeBooking
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Intake regression']);

        return IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
    }
}
