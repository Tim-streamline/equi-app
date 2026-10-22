<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use App\Support\IntakeObsoleteAnswers;
use App\Support\LaminitisSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IntakeObsoleteAnswersTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $config = json_decode(file_get_contents(base_path('tests/Fixtures/intake-safety.json')), true);
        $q = IntakeQuestionnaire::create(['slug' => 'protocol-intake', 'name' => 'Intake', 'safety_config' => $config]);
        $section = $q->sections()->create(['key' => $config['section'], 'title' => 'Safety']);
        foreach (['condition' => ['urgent', 'previous', 'none'], 'resolved' => ['confirmed', 'no'], 'stable' => ['approved', 'no'], 'recovered' => ['recovered', 'uncertain']] as $key => $options) {
            $section->fields()->create(['key' => $key, 'label' => $key, 'type' => 'radio', 'options' => $options]);
        }
        $owner = \App\Models\User::factory()->create();
        $horse = Horse::create(['owner_id' => $owner->id, 'name' => 'Answer cleanup']);
        $booking = IntakeBooking::create(['user_id' => $owner->id, 'horse_id' => $horse->id]);

        return [$q, $section, $booking, app(LaminitisSafety::class), $config];
    }

    private function answer(IntakeBooking $booking, string $field, mixed $value, string $section = 'custom-safety')
    {
        $answer = $booking->answers()->create(['section_id' => $section, 'field_id' => $field, 'value' => json_encode($value)]);
        // Reproduce pre-retirement data for migration 000005.
        app(LaminitisSafety::class)->observe($answer);
        return $answer;
    }

    private function rejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The safety concern must remain unresolved.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('laminitis', $e->errors());
        }
    }

    public function test_cleanup_clears_removed_choices_and_reopens_only_affected_intakes(): void
    {
        [$q, $section, $booking, $safety] = $this->fixture();
        $section->fields()->create(['key' => 'multi', 'label' => 'Multi', 'type' => 'multi', 'options' => ['kept'], 'answer_section_key' => 'original']);
        $section->fields()->create(['key' => 'notes', 'label' => 'Notes', 'type' => 'text']);
        $section->fields()->create(['key' => 'weight', 'label' => 'Weight', 'type' => 'number', 'options' => ['unknown']]);
        $section->fields()->create(['key' => 'file', 'label' => 'File', 'type' => 'file']);
        $removed = $this->answer($booking, 'stable', 'old approved');
        $multi = $this->answer($booking, 'multi', ['kept', 'removed'], 'original');
        $valid = [$this->answer($booking, 'resolved', 'confirmed'), $this->answer($booking, 'notes', 'old approved'), $this->answer($booking, 'weight', '450')];
        $attachment = (string) Str::uuid();
        DB::table('intake_attachments')->insert(['id' => $attachment, 'booking_id' => $booking->id, 'section_key' => 'custom-safety', 'field_key' => 'file', 'name' => 'scan.pdf', 'path' => 'existing/scan.pdf', 'mime' => 'application/pdf']);
        $valid[] = $this->answer($booking, 'file', ['attachment:'.$attachment.':scan.pdf']);
        $before = array_map(fn ($a) => $a->fresh()->getRawOriginal(), $valid);
        $booking->forceFill(['submitted_at' => now(), 'completed_at' => now(), 'intake_status' => 'submitted', 'submission_email_status' => 'sent'])->save();
        $other = IntakeBooking::create(['user_id' => $booking->user_id, 'horse_id' => $booking->horse_id]);
        $this->answer($other, 'stable', 'approved');
        $other->forceFill(['submitted_at' => now(), 'completed_at' => now()])->save();
        $unchanged = $other->fresh()->getRawOriginal();
        $protocol = $booking->protocol->getRawOriginal();

        app(IntakeObsoleteAnswers::class)->run();
        $this->assertDatabaseMissing('intake_answers', ['id' => $removed->id]);
        $this->assertDatabaseMissing('intake_answers', ['id' => $multi->id]);
        foreach ($valid as $i => $answer) {
            $this->assertSame($before[$i], $answer->fresh()->getRawOriginal());
        }
        $this->assertDatabaseHas('intake_attachments', ['id' => $attachment, 'path' => 'existing/scan.pdf']);
        $this->assertNull($booking->fresh()->submitted_at);
        $this->assertNull($booking->fresh()->completed_at);
        $this->assertSame('draft', $booking->fresh()->intake_status);
        $this->assertSame('sent', $booking->fresh()->submission_email_status);
        $this->assertSame($unchanged, $other->fresh()->getRawOriginal());
        $this->assertSame($protocol, $booking->protocol->fresh()->getRawOriginal());
        $this->answer($booking, 'stable', 'approved');
        app(IntakeObsoleteAnswers::class)->run();
        $this->assertSame('"approved"', $booking->answers()->where('field_id', 'stable')->value('value'));
    }

    public function test_cleanup_preserves_valid_aliases_verbatim_and_reopens_only_intakes_with_obsolete_answers(): void
    {
        [$q, $section, $validBooking] = $this->fixture();
        $affected = IntakeBooking::create(['user_id' => $validBooking->user_id, 'horse_id' => $validBooking->horse_id]);
        $cases = [
            ['radio', 'Combinatie van beide', true],
            ['multi', ['Combinatie van beide', 'current'], true],
            ['number', 'old unknown', true],
            ['number', '450', true],
            ['radio', 'removed', false],
            ['radio', 'broken alias', false],
            ['multi', ['Combinatie van beide', 'removed'], false],
            ['number', 'removed', false],
        ];
        $preserved = $removed = [];
        foreach ($cases as $index => [$type, $value, $valid]) {
            $key = 'alias-'.$index;
            $section->fields()->create(['key' => $key, 'label' => $key, 'type' => $type, 'answer_section_key' => 'original',
                'options' => ['Een combinatie van beide', 'current', 'unknown'],
                'answer_aliases' => ['Combinatie van beide' => 'Een combinatie van beide', 'old unknown' => 'unknown', 'broken alias' => 'removed target']]);
            foreach ($valid ? [$validBooking, $affected] : [$affected] as $booking) {
                $answer = $this->answer($booking, $key, $value, 'original');
                if ($valid) {
                    $preserved[$answer->id] = $answer->fresh()->getRawOriginal();
                } else {
                    $removed[] = $answer->id;
                }
            }
        }
        foreach ([$validBooking, $affected] as $booking) {
            $booking->update(['submitted_at' => now(), 'completed_at' => now(), 'intake_status' => 'submitted']);
        }
        $before = $validBooking->fresh()->getRawOriginal();
        $migration = require database_path('migrations/2026_10_22_000005_clear_obsolete_intake_answers.php');
        foreach ([1, 2] as $run) {
            $migration->up();
            foreach ($preserved as $id => $attributes) {
                $this->assertSame($attributes, \App\Models\IntakeAnswer::find($id)?->getRawOriginal(), 'Preserve aliased answer '.$id);
            }
            foreach ($removed as $id) {
                $this->assertDatabaseMissing('intake_answers', ['id' => $id]);
            }
            $this->assertSame($before, $validBooking->fresh()->getRawOriginal());
            $this->assertNull($affected->fresh()->submitted_at);
            $this->assertNull($affected->fresh()->completed_at);
            $this->assertSame('draft', $affected->fresh()->intake_status);
        }
    }

    public function test_removed_recovery_values_require_fresh_current_answers_and_preserve_other_concerns(): void
    {
        [$q, $section, $booking, $safety, $config] = $this->fixture();
        $this->answer($booking, 'condition', 'urgent');
        $report = $booking->safetyReports()->firstOrFail();
        $old = $report->state;
        $old['requirements']['stable'] = ['old approved'];
        $old['configuration']['recovery']['require']['stable'] = ['old approved'];
        unset($old['recovery_fields']); // Restored history without original question options.
        $report->update(['state' => $safety->legacyReportState($old, $booking)]);
        $obsolete = $this->answer($booking, 'stable', 'old approved');
        $other = IntakeBooking::create(['user_id' => $booking->user_id, 'horse_id' => $booking->horse_id]);
        $this->answer($other, 'condition', 'urgent');
        $otherReport = $other->safetyReports()->firstOrFail();
        $otherBefore = $otherReport->getRawOriginal();
        $booking->forceFill(['submitted_at' => now(), 'completed_at' => now()])->save();

        app(IntakeObsoleteAnswers::class)->run();
        $state = $report->fresh()->state;
        $this->assertSame($old['episode_id'], $state['episode_id']);
        $this->assertSame($old['configuration']['triggers'], $state['configuration']['triggers']);
        $this->assertSame(['approved'], $state['requirements']['stable']);
        $this->assertSame(['approved', 'no'], collect($state['recovery_fields'])->firstWhere('id', 'stable')['options']);
        $this->assertDatabaseMissing('intake_answers', ['id' => $obsolete->id]);
        $this->assertSame($otherBefore, $otherReport->fresh()->getRawOriginal());
        $this->rejected(fn () => $safety->release($booking, $state['episode_id'], $booking->user_id, ['resolved' => 'confirmed', 'stable' => 'old approved']));
        $this->rejected(fn () => $safety->release($booking, $state['episode_id'], $booking->user_id, ['resolved' => 'confirmed']));
        $safety->release($booking, $state['episode_id'], $booking->user_id, ['resolved' => 'confirmed', 'stable' => 'approved']);
        $this->assertTrue($safety->blocked($booking->horse->fresh()));
        $safety->release($other, $otherReport->state['episode_id'], $other->user_id, ['resolved' => 'confirmed', 'stable' => 'approved']);
        $released = $report->fresh()->getRawOriginal();
        app(IntakeObsoleteAnswers::class)->run();
        $this->assertSame($released, $report->fresh()->getRawOriginal());
        $this->assertFalse($safety->blocked($booking->horse->fresh()));
    }

    public function test_deleting_a_removed_trigger_answer_keeps_its_pinned_blocking_rule(): void
    {
        [$q, $section, $booking, $safety, $config] = $this->fixture();
        $config['triggers'][0]['block_while_matching'] = true;
        $q->update(['safety_config' => $config]);
        $answer = $this->answer($booking, 'condition', 'urgent');
        $report = $booking->safetyReports()->firstOrFail();
        $section->fields()->where('key', 'condition')->first()->update(['options' => ['none']]);
        app(IntakeObsoleteAnswers::class)->run();
        $this->assertDatabaseMissing('intake_answers', ['id' => $answer->id]);
        $this->assertTrue($safety->blocked($booking->horse->fresh()));
        $declarations = ['resolved' => 'confirmed', 'stable' => 'approved'];
        $this->rejected(fn () => $safety->release($booking, $report->state['episode_id'], $booking->user_id, $declarations));
        $this->answer($booking, 'condition', 'none');
        $safety->release($booking, $report->state['episode_id'], $booking->user_id, $declarations);
        $this->assertFalse($safety->blocked($booking->horse->fresh()));
    }
}
