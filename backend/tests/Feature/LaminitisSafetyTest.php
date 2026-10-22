<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\User;
use App\Support\IntakeProgress;
use App\Support\IntakeReview;
use App\Support\IntakeSubmission;
use App\Support\LaminitisSafety;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    private function rejected(callable $operation): void
    {
        try { $operation(); $this->fail('Expected a safety hold.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('laminitis', $e->errors()); }
    }

    public function test_acute_hold_survives_answer_changes_deletion_and_another_intake(): void
    {
        $booking = $this->booking();
        $this->answer($booking, 'hoefbevangenheid', self::ACUTE[1]);
        $episode = $booking->horse->fresh()->laminitis_safety['episode_id'];
        $this->answer($booking, 'hoefbevangenheid', 'Nee, voor zover bekend niet');
        $booking->answers()->delete();
        $other = IntakeBooking::create(['user_id' => $booking->user_id, 'horse_id' => $booking->horse_id]);
        $safety = app(LaminitisSafety::class);
        $this->assertTrue($safety->blocked($other->horse));
        $this->assertSame($episode, $other->horse->laminitis_safety['episode_id']);
        $missing = app(IntakeProgress::class)->forBooking($other)['missing'];
        $this->assertContains(['section' => 'medisch', 'field' => self::RECOVERY[0]], $missing);
    }

    public function test_save_is_allowed_but_submission_and_protocol_activation_are_blocked(): void
    {
        $booking = $this->booking();
        $this->answer($booking, 'hoefbevangenheid', 'nu acuut');
        $this->answer($booking, 'hoefbevangenheid-begonnen', 'voorjaar 2026');
        $this->assertSame(2, $booking->answers()->count());
        $this->rejected(fn () => app(IntakeSubmission::class)->complete($booking));
        $protocol = $booking->protocol;
        $this->rejected(fn () => $protocol->fill(['status' => 'active', 'published_at' => now()])->save());
        $this->assertNull($protocol->fresh()->published_at);
        $this->assertTrue(app(IntakeReview::class)->forBooking($booking)['blocked']);
    }

    public function test_release_requires_two_yes_answers_and_the_current_episode(): void
    {
        $booking = $this->booking();
        $this->answer($booking, 'hoefbevangenheid', self::ACUTE[1]);
        $episode = $booking->horse->fresh()->laminitis_safety['episode_id'];
        $safety = app(LaminitisSafety::class);
        $this->answer($booking, self::RECOVERY[0], 'Ja');
        $this->rejected(fn () => $safety->release($booking, $episode, $booking->user_id));
        $this->answer($booking, self::RECOVERY[1], 'Nee');
        $this->rejected(fn () => $safety->release($booking, $episode, $booking->user_id));
        $this->answer($booking, self::RECOVERY[1], 'Ja');
        $this->assertTrue($safety->blocked($booking->horse->fresh()));
        $state = $safety->release($booking, $episode, $booking->user_id);
        $this->assertSame('owner_reported_veterinary_confirmation', $state['release_source']);
        $this->assertFalse($safety->blocked($booking->horse->fresh()));
        $this->assertFalse(app(IntakeReview::class)->forBooking($booking)['blocked']);
        IntakeField::query()->update(['optional' => true]);
        $this->assertNotNull(app(IntakeSubmission::class)->complete($booking->fresh())->submitted_at);
    }

    public function test_new_episode_cannot_reuse_an_old_release_even_with_stale_yes_answers(): void
    {
        $booking = $this->booking();
        $safety = app(LaminitisSafety::class);
        $this->answer($booking, 'hoefbevangenheid', self::ACUTE[1]);
        $old = $booking->horse->fresh()->laminitis_safety['episode_id'];
        foreach (self::RECOVERY as $key) $this->answer($booking, $key, 'Ja');
        $safety->release($booking, $old, $booking->user_id);
        $this->answer($booking, 'hoefbevangenheid', self::PAST[1]);
        $this->answer($booking, 'hoefbevangenheid', self::ACUTE[1]);
        $this->rejected(fn () => $safety->release($booking, $old, $booking->user_id));
        $this->assertTrue($safety->blocked($booking->horse->fresh()));
        $this->assertCount(1, $booking->horse->fresh()->laminitis_safety['history']);
    }

    public function test_unresolved_or_unknown_recovery_blocks_while_risk_or_recovered_does_not(): void
    {
        $booking = $this->booking();
        $safety = app(LaminitisSafety::class);
        $this->answer($booking, 'hoefbevangenheid', 'Mijn paard heeft een verhoogd risico op hoefbevangenheid');
        $this->assertFalse($safety->blocked($booking->horse->fresh()));
        $this->answer($booking, 'hoefbevangenheid', self::PAST[1]);
        $this->answer($booking, 'hoefbevangenheid-hersteld', 'Ja');
        $this->assertFalse($safety->blocked($booking->horse->fresh()));
        $this->answer($booking, 'hoefbevangenheid-hersteld', 'Weet ik niet');
        $episode = $booking->horse->fresh()->laminitis_safety['episode_id'];
        foreach (self::RECOVERY as $key) $this->answer($booking, $key, 'Ja');
        $this->rejected(fn () => $safety->release($booking, $episode, $booking->user_id));
        $this->answer($booking, 'hoefbevangenheid-hersteld', 'Ja');
        $this->assertTrue($safety->blocked($booking->horse->fresh()));
        $safety->release($booking, $episode, $booking->user_id);
        $this->assertFalse($safety->blocked($booking->horse->fresh()));
    }

    public function test_post_submission_recovery_is_a_separate_declaration_preserving_original_answers(): void
    {
        $booking = $this->booking();
        $this->answer($booking, 'hoefbevangenheid', 'nu acuut');
        $before = $booking->answers()->pluck('value', 'id')->all();
        $booking->forceFill(['submitted_at' => now(), 'completed_at' => now()])->save();
        $safety = app(LaminitisSafety::class);
        $state = $safety->release($booking, $booking->horse->fresh()->laminitis_safety['episode_id'], $booking->user_id,
            array_fill_keys(self::RECOVERY, 'Ja'));
        $this->assertNotEmpty($state['released_at']);
        $this->assertSame($before, $booking->answers()->pluck('value', 'id')->all());
    }

    public function test_admin_configured_acute_answer_is_detected_and_old_confirmations_are_not_automatic_release(): void
    {
        $booking = $this->booking();
        $q = \App\Models\IntakeQuestionnaire::firstOrFail();
        $config = $q->safety_config;
        $config['triggers'][0]['when']['hoefbevangenheid'] = ['Momenteel acuut'];
        $q->update(['safety_config' => $config]);
        $this->answer($booking, 'hoefbevangenheid', 'Momenteel acuut');
        $state = $booking->horse->fresh()->laminitis_safety;
        $this->assertSame('acute', $state['kind']);
        $this->assertSame($booking->user_id, $state['reported_by']);
        foreach (self::RECOVERY as $key) $this->answer($booking, $key, 'Ja');
        $this->assertTrue(app(LaminitisSafety::class)->blocked($booking->horse->fresh()));
    }
}
