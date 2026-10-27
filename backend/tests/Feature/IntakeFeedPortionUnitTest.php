<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\User;
use App\Support\IntakeAnswersPdf;
use App\Support\IntakeReview;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeFeedPortionUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_adds_gram_or_kilo_choice_once_and_keeps_answers(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $field = IntakeField::where('key', 'huidige-bijvoeding')->sole();
        $legacy = array_map(function ($column) {
            if (($column['id'] ?? null) === 'hoeveelheid') return ['id' => 'hoeveelheid', 'label' => 'Hoeveelheid per voerbeurt', 'type' => 'number', 'unit' => 'kg'];
            return $column;
        }, array_values(array_filter($field->repeater_sub, fn ($column) => ($column['id'] ?? null) !== 'eenheid')));
        $field->update(['repeater_sub' => $legacy]);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Unit tests']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $answer = $booking->answers()->create(['section_id' => 'voer', 'field_id' => 'huidige-bijvoeding', 'value' => json_encode([['merk' => 'A', 'hoeveelheid' => '2 scheppen']])]);
        $before = $answer->fresh()->getAttributes();

        $migration = require database_path('migrations/2026_10_27_000005_add_feed_portion_unit_choice.php');
        $migration->up();
        $migration->up();

        $sub = collect($field->fresh()->repeater_sub)->keyBy('id');
        $this->assertSame(1, collect($field->fresh()->repeater_sub)->where('id', 'eenheid')->count());
        $this->assertSame(['gram', 'kilo'], $sub['eenheid']['options']);
        $this->assertSame('eenheid', $sub['hoeveelheid']['unitField']);
        $this->assertArrayNotHasKey('unit', $sub['hoeveelheid']);
        $this->assertSame('Weet je het alleen in scheppen? Weeg één schep een keer af.', $sub['hoeveelheid']['hint']);
        $this->assertSame($before, $answer->fresh()->getAttributes());
    }

    public function test_review_and_pdf_show_the_chosen_unit(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Unit tests']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $booking->answers()->create(['section_id' => 'voer', 'field_id' => 'bijvoeding-nu', 'value' => json_encode('Ja')]);
        $booking->answers()->create(['section_id' => 'voer', 'field_id' => 'huidige-bijvoeding', 'value' => json_encode([
            ['merk' => 'Mineraal', 'product' => 'Balans', 'hoeveelheid' => '50', 'eenheid' => 'gram'],
            ['merk' => 'Brok', 'product' => 'Basis', 'hoeveelheid' => '1,5', 'eenheid' => 'kilo'],
        ])]);
        $row = collect(app(IntakeReview::class)->forBooking($booking)['sections'])->flatMap(fn ($s) => $s['rows'])->firstWhere('key', 'huidige-bijvoeding');
        $this->assertSame('eenheid', collect($row['sub'])->firstWhere('id', 'hoeveelheid')['unitField']);
        $html = app(IntakeAnswersPdf::class)->html($booking);
        $this->assertStringContainsString('50 gram', $html);
        $this->assertStringContainsString('1,5 kilo', $html);
        $this->assertStringNotContainsString('<strong>Eenheid:</strong>', $html);
    }
}
