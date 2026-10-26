<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\User;
use App\Support\IntakeDates;
use App\Support\IntakeProgress;
use App\Support\IntakeReview;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntakeDateFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): IntakeBooking
    {
        $this->travelTo(now()->setDate(2026, 10, 21));
        $this->seed(IntakeQuestionnaireSeeder::class);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Date tests']);
        return IntakeBooking::create(['horse_id' => $horse->id, 'user_id' => $user->id]);
    }

    public function test_date_contract_matches_shared_migration_and_validation_examples(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 21));
        $dates = app(IntakeDates::class);
        $cases = json_decode(file_get_contents(base_path('tests/Fixtures/intake-dates.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            if (isset($case['expected'])) $this->assertSame($case['expected'], $dates->normalize($case['raw'], $case['config'] ?? []));
            $this->assertSame($case['valid'], $dates->answered($case['raw'], $case['config'] ?? []), json_encode($case));
        }
    }

    public function test_unknown_completes_a_question_but_partial_or_future_dates_do_not(): void
    {
        $booking = $this->booking();
        $answer = $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'vacc-laatst', 'value' => json_encode(['version' => 1, 'precision' => 'month', 'month' => 4])]);
        $missing = fn () => app(IntakeProgress::class)->forBooking($booking->load('answers'))['missing'];
        $target = ['section' => 'medisch', 'field' => 'vacc-laatst'];
        $this->assertContains($target, $missing());
        $answer->update(['value' => json_encode(['version' => 1, 'precision' => 'month', 'month' => 4, 'year' => 2027])]);
        $this->assertContains($target, $missing());
        $answer->update(['value' => json_encode(['version' => 1, 'precision' => 'unknown'])]);
        $this->assertNotContains($target, $missing());
    }

    public function test_ownership_date_disallows_unknown_without_rewriting_existing_answers(): void
    {
        $booking = $this->booking();
        $field = IntakeField::where('key', 'sinds-bezit')->firstOrFail();
        $field->update(['date_config' => ['mode' => 'single', 'allow_future' => false, 'year_label' => 'Eigen jaartal']]);
        $answer = $booking->answers()->create(['section_id' => 'paard', 'field_id' => 'sinds-bezit', 'value' => json_encode(['version' => 1, 'precision' => 'unknown'])]);
        $original = $answer->value;
        $migration = require database_path('migrations/2026_10_26_140000_require_known_horse_ownership_date.php');
        $migration->up();
        $migration->up();
        $this->assertFalse($field->fresh()->date_config['allow_unknown']);
        $this->assertSame('Eigen jaartal', $field->fresh()->date_config['year_label']);
        $this->assertSame($original, $answer->fresh()->value);
        $missing = fn () => app(IntakeProgress::class)->forBooking($booking->load('answers'))['missing'];
        $target = ['section' => 'paard', 'field' => 'sinds-bezit'];
        $this->assertContains($target, $missing());
        $answer->update(['value' => json_encode(['version' => 1, 'precision' => 'month', 'month' => 8, 'year' => 2014])]);
        $this->assertNotContains($target, $missing());
        app(IntakeDates::class)->validateConfig(['allow_unknown' => false]);
        $this->assertTrue(app(IntakeDates::class)->answered('Weet ik niet'));
    }

    public function test_nested_dates_are_validated_and_review_and_pdf_receive_readable_values(): void
    {
        $booking = $this->booking();
        $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'medicatie-nu', 'value' => json_encode('Ja')]);
        $answer = $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'medicatie-nu-details', 'value' => json_encode([['naam' => 'Middel A', 'sinds' => 'juli 2026']])]);
        $this->assertSame('month', json_decode($answer->fresh()->value, true)[0]['sinds']['precision']);
        $review = app(IntakeReview::class)->forBooking($booking->load('answers'));
        $row = collect($review['sections'])->flatMap(fn ($s) => $s['rows'])->firstWhere('key', 'medicatie-nu-details');
        $this->assertSame('juli 2026', $row['value'][0]['sinds']);
        $this->assertSame(7, $row['structured_value'][0]['sinds']['month']);
        $answer->update(['value' => json_encode([['naam' => 'Middel A', 'sinds' => ['version' => 1, 'precision' => 'month', 'month' => 13, 'year' => 2026]]])]);
        $this->assertContains(['section' => 'medisch', 'field' => 'medicatie-nu-details'], app(IntakeProgress::class)->forBooking($booking->load('answers'))['missing']);
    }

    public function test_migration_preserves_ambiguous_text_and_other_repeater_values_and_is_repeatable(): void
    {
        $booking = $this->booking();
        IntakeField::where('key', 'vacc-laatst')->update(['type' => 'text']);
        $vaccine = $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'vacc-laatst', 'value' => json_encode('voorjaar 2024')]);
        $field = IntakeField::where('key', 'medische-gebeurtenissen')->firstOrFail();
        $subs = $field->repeater_sub;
        foreach ($subs as &$sub) if ($sub['id'] === 'datum') $sub['type'] = 'text';
        unset($sub);
        $field->update(['repeater_sub' => $subs]);
        $events = $booking->answers()->create(['section_id' => 'geschiedenis', 'field_id' => 'medische-gebeurtenissen', 'value' => json_encode([['datum' => 'juli 2026', 'gebeurtenis' => 'Originele beschrijving', '_private' => 'behouden']])]);
        $timestamp = $vaccine->updated_at;
        $migration = require database_path('migrations/2026_10_21_000011_structure_intake_dates.php');
        $migration->up();
        $value = json_decode($vaccine->fresh()->value, true);
        $this->assertSame(['version' => 1, 'precision' => 'legacy', 'text' => 'voorjaar 2024'], $value);
        $this->assertTrue($timestamp->equalTo($vaccine->fresh()->updated_at));
        $event = json_decode($events->fresh()->value, true)[0];
        $this->assertSame(7, $event['datum']['month']);
        $this->assertSame('Originele beschrijving', $event['gebeurtenis']);
        $this->assertSame('behouden', $event['_private']);
        $migration->up();
        $this->assertSame($value, json_decode($vaccine->fresh()->value, true));
    }
}
