<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\User;
use App\Support\IntakeProgress;
use App\Support\IntakeSubmission;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LaminitisSafetyTest extends TestCase
{
    use RefreshDatabase;
    private const ACUTE = ['nu acuut', 'Ja, mijn paard is momenteel hoefbevangen'];
    private const PAST = ['in verleden gehad', 'Ja, mijn paard is in het verleden hoefbevangen geweest'];
    private const RECOVERY = ['hoefbevangenheid-acute-fase-voorbij', 'hoefbevangenheid-stabiel'];

    private function booking(): IntakeBooking
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Laminitis tests']);
        return IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
    }

    private function answer(IntakeBooking $booking, string $key, string $value): void
    {
        $booking->answers()->updateOrCreate(['section_id' => 'medisch', 'field_id' => $key], ['value' => json_encode($value)]);
    }

    public function test_blocking_medical_answers_use_configured_messages_and_normal_answer_changes(): void
    {
        $booking = $this->booking();
        $blocks = app(\App\Support\IntakeSubmissionBlocks::class);
        foreach (self::ACUTE as $answer) {
            $this->answer($booking, 'hoefbevangenheid', $answer);
            $this->assertNotEmpty($blocks->forBooking($booking));
            try {
                app(IntakeSubmission::class)->complete($booking);
                $this->fail('A blocking answer must prevent submission.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('intake', $e->errors());
            }
        }
        $this->answer($booking, 'hoefbevangenheid', self::PAST[1]);
        foreach (['Nee', 'Weet ik niet'] as $answer) {
            $this->answer($booking, 'hoefbevangenheid-hersteld', $answer);
            $this->assertNotEmpty($blocks->forBooking($booking));
        }
        $this->answer($booking, 'hoefbevangenheid-hersteld', 'Ja');
        $this->assertSame([], $blocks->forBooking($booking));
        $this->assertDatabaseCount('intake_safety_reports', 0);
        $this->assertNull($booking->horse->fresh()->laminitis_safety);
        $missing = app(IntakeProgress::class)->forBooking($booking)['missing'];
        foreach (self::RECOVERY as $key) $this->assertNotContains(['section' => 'medisch', 'field' => $key], $missing);
    }
}
