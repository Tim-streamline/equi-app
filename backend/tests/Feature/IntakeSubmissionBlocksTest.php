<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use App\Models\IntakeSafetyReport;
use App\Models\User;
use App\Support\IntakeSubmission;
use App\Support\IntakeSubmissionBlocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IntakeSubmissionBlocksTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $q = IntakeQuestionnaire::create(['slug' => 'protocol-intake', 'name' => 'Intake']);
        $section = $q->sections()->create(['key' => 'health', 'title' => 'Health']);
        $field = $section->fields()->create(['key' => 'condition', 'label' => 'Condition', 'type' => 'radio', 'options' => ['acute', 'none'],
            'submission_blocks' => [['answer' => 'acute', 'message' => 'Contact your vet before submitting.']]]);
        $owner = User::factory()->create();
        $horse = Horse::create(['owner_id' => $owner->id, 'name' => 'Answer blocks']);
        $booking = IntakeBooking::create(['user_id' => $owner->id, 'horse_id' => $horse->id]);

        return [$booking, $field, $section];
    }

    private function answer(IntakeBooking $booking, string $field, mixed $value, string $section = 'health'): void
    {
        $booking->answers()->updateOrCreate(['section_id' => $section, 'field_id' => $field], ['value' => json_encode($value)]);
    }

    public function test_current_answers_block_submission_and_no_recovery_confirmation_is_needed_after_they_change(): void
    {
        [$booking] = $this->fixture();
        $this->answer($booking, 'condition', 'acute');
        $this->assertDatabaseCount('intake_safety_reports', 0);
        try {
            app(IntakeSubmission::class)->complete($booking);
            $this->fail('The blocking answer must prevent submission.');
        } catch (ValidationException $e) {
            $this->assertSame(['Contact your vet before submitting.'], $e->errors()['intake']);
        }
        $this->assertNull($booking->fresh()->submitted_at);
        $this->answer($booking, 'condition', 'none');
        app(IntakeSubmission::class)->complete($booking->fresh());
        $this->assertNotNull($booking->fresh()->submitted_at);
        $this->assertSame('"none"', $booking->answers()->where('field_id', 'condition')->value('value'));
        $this->assertDatabaseCount('intake_safety_reports', 0);
    }

    public function test_protocol_preparation_depends_on_submission_not_later_answer_rule_changes(): void
    {
        [$booking, $field] = $this->fixture();
        $this->answer($booking, 'condition', 'none');
        try {
            $booking->fresh()->protocol->assertIntakeSubmitted();
            $this->fail('Even a safe answer is insufficient until the intake is submitted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('intake', $e->errors());
        }
        app(IntakeSubmission::class)->complete($booking);
        $field->update(['submission_blocks' => [['answer' => 'none', 'message' => 'A later rule']]]);
        $this->assertNotEmpty(app(IntakeSubmissionBlocks::class)->forBooking($booking));
        $booking->fresh()->protocol->assertIntakeSubmitted();
        $this->assertSame('"none"', $booking->answers()->where('field_id', 'condition')->value('value'));
    }

    public function test_multi_answers_visibility_moved_answers_and_messages_use_the_same_rules(): void
    {
        [$booking, $field, $section] = $this->fixture();
        $section->fields()->create(['key' => 'symptoms', 'label' => 'Symptoms', 'type' => 'multi', 'options' => ['pain', 'other'],
            'answer_section_key' => 'old', 'show_if' => ['condition' => 'acute'],
            'submission_blocks' => [['answer' => 'pain', 'message' => 'Wait before submitting.']]]);
        $this->answer($booking, 'condition', 'acute');
        $this->answer($booking, 'symptoms', ['other', 'pain'], 'old');
        $blocks = app(IntakeSubmissionBlocks::class);
        $this->assertSame(['Contact your vet before submitting.', 'Wait before submitting.'], array_column($blocks->forBooking($booking), 'message'));
        $this->answer($booking, 'condition', 'none');
        $this->assertSame([], $blocks->forBooking($booking));
        $field->update(['submission_blocks' => [['answer' => 'none', 'message' => 'New editable message']]]);
        $this->assertSame('New editable message', $blocks->forBooking($booking)[0]['message']);
    }

    public function test_other_intakes_and_archived_recovery_reports_do_not_create_a_separate_hold(): void
    {
        [$booking] = $this->fixture();
        $other = IntakeBooking::create(['user_id' => $booking->user_id, 'horse_id' => $booking->horse_id]);
        $this->answer($other, 'condition', 'acute');
        $report = IntakeSafetyReport::create(['horse_id' => $booking->horse_id, 'intake_booking_id' => $other->id,
            'state' => ['episode_id' => (string) Str::uuid(), 'kind' => 'historical']]);
        $before = $report->fresh()->getRawOriginal();
        $this->answer($booking, 'condition', 'none');
        $this->answer($booking, '_laminitis_hold', 'old marker');
        app(IntakeSubmission::class)->complete($booking);
        $this->assertNotNull($booking->fresh()->submitted_at);
        $this->assertSame($before, $report->fresh()->getRawOriginal());
        $this->assertCount(1, app(IntakeSubmissionBlocks::class)->forBooking($other));
    }
}
