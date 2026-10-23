<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\IntakeProgress;
use App\Support\IntakeReview;
use App\Support\IntakeSignalPreparation;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IntakeSuspicionSignalsTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): IntakeBooking
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Signal tests']);

        return IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
    }

    public function test_migration_preserves_ems_selections_and_kpu_uploads_without_overwriting_new_text(): void
    {
        $booking = $this->booking();
        $section = IntakeQuestionnaire::where('slug', 'protocol-intake')->firstOrFail()->sections()->where('key', 'medisch')->firstOrFail();
        $section->fields()->whereIn('key', ['ems-vermoeden-toelichting', 'kpu-uitslag-beschikbaar'])->delete();
        foreach (['ems-vermoeden-redenen' => 'multi', 'ems-vermoeden-anders' => 'text'] as $key => $type) {
            $section->fields()->create(['key' => $key, 'label' => $key, 'type' => $type, 'active' => true, 'order' => 100]);
        }
        $old = $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'ems-vermoeden-redenen',
            'value' => json_encode(['Mijn paard valt moeilijk af', 'Anders, namelijk'])]);
        $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'ems-vermoeden-anders', 'value' => json_encode('Een harde vetkam')]);
        $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'kpu-attest',
            'value' => json_encode(['attachment:00000000-0000-4000-8000-000000000001:uitslag.pdf'])]);
        $migration = require database_path('migrations/2026_10_21_000008_refine_suspicions_and_kpu_results.php');
        $migration->up();
        $this->assertSame(json_encode(['Mijn paard valt moeilijk af', 'Anders, namelijk']), $old->fresh()->value);
        $new = $booking->answers()->where('field_id', 'ems-vermoeden-toelichting')->firstOrFail();
        $this->assertSame("Mijn paard valt moeilijk af\nAnders, namelijk\nEen harde vetkam", json_decode($new->value));
        $this->assertFalse($section->fields()->where('key', 'ems-vermoeden-redenen')->firstOrFail()->active);
        $this->assertSame('Ja', json_decode($booking->answers()->where('field_id', 'kpu-uitslag-beschikbaar')->firstOrFail()->value));
        $new->update(['value' => json_encode('Eigen nieuwe toelichting')]);
        $migration->up();
        $this->assertSame('Eigen nieuwe toelichting', json_decode($new->fresh()->value));
    }

    public function test_kpu_requires_a_received_file_only_for_the_yes_branch(): void
    {
        $booking = $this->booking();
        $status = $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'kpu-status', 'value' => json_encode('Aangetoond met urineonderzoek')]);
        $availability = $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'kpu-uitslag-beschikbaar', 'value' => json_encode('Ja')]);
        $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'kpu-attest',
            'value' => json_encode(['attachment:00000000-0000-4000-8000-000000000001:uitslag.pdf'])]);
        $missing = fn () => app(IntakeProgress::class)->forBooking($booking->load('answers'))['missing'];
        $target = ['section' => 'medisch', 'field' => 'kpu-attest'];
        $this->assertContains($target, $missing());
        $attachment = new IntakeAttachment(['booking_id' => $booking->id, 'section_key' => 'medisch', 'field_key' => 'kpu-attest',
            'name' => 'uitslag.pdf', 'path' => 'intake/test.pdf', 'mime' => 'application/pdf']);
        $attachment->id = '00000000-0000-4000-8000-000000000001';
        $attachment->save();
        $this->assertNotContains($target, $missing());
        $attachment->delete();
        $availability->update(['value' => json_encode('Nee')]);
        $this->assertNotContains($target, $missing());
        $availability->update(['value' => json_encode('Ja')]);
        $status->update(['value' => json_encode('Ik vermoed dit')]);
        $this->assertNotContains($target, $missing());
        $this->assertContains(['section' => 'medisch', 'field' => 'kpu-vermoeden-toelichting'], $missing());
    }

    public function test_review_exposes_visible_owner_suspicions_with_provenance_and_no_protocol_diagnosis(): void
    {
        $booking = $this->booking();
        foreach (['ir', 'ems', 'kpu'] as $condition) {
            $booking->answers()->create(['section_id' => 'medisch', 'field_id' => $condition.'-status', 'value' => json_encode('Ik vermoed dit')]);
            $booking->answers()->create(['section_id' => 'medisch', 'field_id' => $condition.'-vermoeden-toelichting', 'value' => json_encode('Mijn paard heeft geen vetkam.')]);
        }
        IntakeField::where('key', 'ems-vermoeden-toelichting')->firstOrFail()->update(['protocol_if' => ['non-empty' => 'Diagnose EMS']]);
        $review = app(IntakeReview::class)->forBooking($booking->load('answers'));
        $this->assertCount(3, $review['owner_reported_inputs']);
        foreach ($review['owner_reported_inputs'] as $input) {
            $this->assertSame('owner_reported', $input['source']);
            $this->assertSame('unconfirmed', $input['clinical_status']);
            $this->assertArrayNotHasKey('diagnosis', $input);
        }
        $this->assertNotContains('Diagnose EMS', array_merge([], ...array_column($review['triggers'], 'protocol')));
        $booking->answers()->where('field_id', 'ems-status')->firstOrFail()->update(['value' => json_encode('Voor zover ik weet niet')]);
        $review = app(IntakeReview::class)->forBooking($booking->load('answers'));
        $this->assertCount(2, $review['owner_reported_inputs']);
    }

    public function test_future_extractor_output_requires_current_literal_evidence_and_cannot_supply_diagnoses(): void
    {
        $this->booking();
        $field = IntakeField::where('key', 'ir-vermoeden-toelichting')->firstOrFail();
        $service = app(IntakeSignalPreparation::class);
        $text = 'Mijn paard heeft geen vetkam, maar valt moeilijk af.';
        $input = $service->input($field, $text, 'medisch');
        $result = ['source_hash' => $input['source_hash'], 'signals' => [
            ['code' => 'vetkam', 'evidence' => 'geen vetkam', 'polarity' => 'absent'],
            ['code' => 'moeite_met_afvallen', 'evidence' => 'valt moeilijk af', 'polarity' => 'present'],
        ]];
        $signals = $service->normalize($field, $text, 'medisch', $result);
        $this->assertSame('absent', $signals[0]['polarity']);
        $this->assertSame('owner_reported', $signals[1]['source']);
        $this->assertSame('unconfirmed', $signals[1]['clinical_status']);
        $this->assertSame('unreviewed', $signals[1]['review_status']);
        foreach ([
            [...$result, 'diagnosis' => 'IR'],
            [...$result, 'source_hash' => 'outdated'],
            [...$result, 'signals' => [['code' => 'vetkam', 'evidence' => 'niet genoemde vetkam', 'polarity' => 'present']]],
            [...$result, 'signals' => [['code' => 'diagnose_ir', 'evidence' => 'vetkam', 'polarity' => 'present']]],
        ] as $invalid) {
            try {
                $service->normalize($field, $text, 'medisch', $invalid);
                $this->fail('Invalid extractor output was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }
}
