<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use App\Models\IntakeSafetyReport;
use App\Models\User;
use App\Support\LaminitisSafety;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IntakeSafetyUpgradeTest extends TestCase
{
    use DatabaseMigrations;

    // Last migration on origin/master at 25575b37. Unlike RefreshDatabase,
    // these tests insert customer data BEFORE any migration under review runs.
    private const BASE = '2026_10_20_000002_add_weight_measurement_date_question.php';
    private const RULES = '2026_10_22_000001_configure_intake_safety_rules.php';
    private const REPORTS = '2026_10_22_000002_create_intake_safety_reports.php';
    private const DISTINCT = '2026_10_22_000004_restore_distinct_intake_safety_concerns.php';
    private const OBSOLETE = '2026_10_22_000005_clear_obsolete_intake_answers.php';
    private const ANSWER_BLOCKS = '2026_10_22_000006_replace_recovery_with_answer_blocks.php';
    private const REPAIR = '2026_10_22_000003_backfill_missing_intake_safety_reports.php';

    protected function migrateFreshUsing(): array
    {
        return ['--realpath' => true, '--path' => $this->pathsThrough(self::BASE)];
    }

    private function pathsThrough(string $last): array
    {
        return array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => basename($path) <= $last));
    }

    private function upgradeThrough(string $last): void
    {
        $this->artisan('migrate', ['--realpath' => true, '--path' => $this->pathsThrough($last)])->assertExitCode(0);
    }

    private function fixtures(): array
    {
        $this->assertFalse(Schema::hasTable('intake_safety_reports'));
        $this->assertFalse(Schema::hasColumn('horses', 'laminitis_safety'));
        $q = IntakeQuestionnaire::create(['slug' => 'protocol-intake', 'name' => 'Existing questionnaire']);
        $section = $q->sections()->create(['key' => 'medisch', 'title' => 'Medisch']);
        // Original question anchors; later migrations supply their follow-ups.
        foreach (['hoefbevangenheid', 'ems-status', 'kpu-status', 'kpu-attest'] as $order => $key) {
            $section->fields()->create(['key' => $key, 'label' => $key, 'type' => 'text', 'order' => $order]);
        }
        $owner = User::factory()->create();
        $horse = Horse::create(['owner_id' => $owner->id, 'name' => 'Upgrade horse']);
        $bookings = [];
        foreach (range(1, 4) as $i) {
            $bookings[] = IntakeBooking::create(['user_id' => $owner->id, 'horse_id' => $horse->id]);
        }
        [$a, $b, $c, $safe] = $bookings;
        $this->answer($a, 'hoefbevangenheid', 'Ja, mijn paard is momenteel hoefbevangen');
        $this->answer($b, 'hoefbevangenheid', 'Ja, mijn paard is momenteel hoefbevangen');
        $b->forceFill(['submitted_at' => now(), 'completed_at' => now()])->save();
        $this->answer($c, 'hoefbevangenheid', 'Ja, mijn paard is in het verleden hoefbevangen geweest');
        $this->answer($c, 'hoefbevangenheid-hersteld', 'Weet ik niet');
        $this->answer($safe, 'hoefbevangenheid', 'Nee, voor zover bekend niet');

        return [$horse, $a, $b, $c, $safe];
    }

    private function answer(IntakeBooking $booking, string $key, mixed $value): void
    {
        // Pre-upgrade data must not run today's model observers.
        DB::table('intake_answers')->insert(['id' => (string) Str::uuid(), 'response_id' => $booking->id,
            'section_id' => 'medisch', 'field_id' => $key, 'value' => json_encode($value),
            'created_at' => '2026-10-19 12:00:00', 'updated_at' => '2026-10-19 12:00:00']);
    }

    private function rows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->keyBy('id')->toArray();
    }

    private function assertHeld(Horse $horse): void
    {
        $safety = app(LaminitisSafety::class);
        $this->assertTrue($safety->blocked($horse->fresh()));
        foreach (['submit', 'preview', 'protocol'] as $action) {
            try {
                $safety->assertAllowed($horse->fresh(), $action);
                $this->fail('An unresolved report must block '.$action);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('laminitis', $e->errors());
            }
        }
    }

    private function release(IntakeBooking $booking, IntakeSafetyReport $report): void
    {
        $declarations = array_map(fn ($accepted) => $accepted[0], $report->state['requirements']);
        app(LaminitisSafety::class)->release($booking, $report->state['episode_id'], $booking->user_id, $declarations);
    }

    public function test_origin_master_upgrade_preserves_draft_uploads_answers_and_all_intake_concerns(): void
    {
        [$horse, $a, $b, $c, $safe] = $this->fixtures();
        $attachment = (string) Str::uuid();
        DB::table('intake_attachments')->insert(['id' => $attachment, 'booking_id' => $a->id,
            'section_key' => 'medisch', 'field_key' => 'kpu-attest', 'name' => 'uitslag.pdf',
            'path' => 'existing/uitslag.pdf', 'mime' => 'application/pdf']);
        $this->answer($a, 'kpu-attest', ['attachment:'.$attachment.':uitslag.pdf']);
        $this->answer($a, 'ems-vermoeden-redenen', ['Mijn paard valt moeilijk af', 'Anders, namelijk']);
        $this->answer($a, 'ems-vermoeden-anders', 'Een harde vetkam');
        $original = $this->rows('intake_answers');
        $attachments = $this->rows('intake_attachments');
        $bookings = $this->rows('intake_bookings');
        $timestamp = $horse->fresh()->updated_at;

        $this->upgradeThrough(self::RULES);
        $this->assertFalse(Schema::hasTable('intake_safety_reports'));
        $this->assertSame('Ja', json_decode($a->answers()->where('field_id', 'kpu-uitslag-beschikbaar')->value('value')));
        $this->assertSame("Mijn paard valt moeilijk af\nAnders, namelijk\nEen harde vetkam", json_decode($a->answers()->where('field_id', 'ems-vermoeden-toelichting')->value('value')));
        $legacy = $horse->fresh()->laminitis_safety;
        $this->upgradeThrough(self::REPAIR);

        $reports = $horse->safetyReports()->get()->keyBy('intake_booking_id');
        $this->assertCount(3, $reports);
        $this->assertFalse($reports->has($safe->id));
        $imported = $reports[$legacy['booking_id']]->state;
        $this->assertSame($legacy, array_intersect_key($imported, $legacy));
        $this->assertSame(['Ja'], $reports[$c->id]->state['requirements']['hoefbevangenheid-hersteld']);
        $this->assertEquals($timestamp, $horse->fresh()->updated_at);
        foreach ($original as $id => $row) {
            $this->assertEquals($row, $this->rows('intake_answers')[$id]);
        }
        $this->assertEquals($attachments, $this->rows('intake_attachments'));
        $this->assertEquals($bookings, $this->rows('intake_bookings'));
        $allAnswers = $this->rows('intake_answers');
        $this->assertHeld($horse);
        $this->release($a, $reports[$a->id]);
        $this->assertHeld($horse);
        $this->release($a, $reports[$b->id]);
        $this->assertHeld($horse);
        try {
            app(LaminitisSafety::class)->release($a, $reports[$c->id]->state['episode_id'], $a->user_id,
                ['hoefbevangenheid-acute-fase-voorbij' => 'Ja', 'hoefbevangenheid-stabiel' => 'Ja']);
            $this->fail('The additional concern must retain its conditional recovery requirement');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('laminitis', $e->errors());
        }
        $this->assertHeld($horse);
        $this->release($a, $reports[$c->id]);
        $this->assertFalse(app(LaminitisSafety::class)->blocked($horse->fresh()));
        $this->assertEquals($allAnswers, $this->rows('intake_answers'));
        $saved = $this->rows('intake_safety_reports');
        (require database_path('migrations/'.self::REPORTS))->up();
        (require database_path('migrations/'.self::REPAIR))->up();
        $this->assertEquals($saved, $this->rows('intake_safety_reports'));
        $this->assertFalse(app(LaminitisSafety::class)->blocked($horse->fresh()));
    }

    public function test_repair_after_old_import_preserves_releases_and_recovers_missing_reports_even_without_a_horse_hold(): void
    {
        [$horse, $a, $b, $c] = $this->fixtures();
        $this->upgradeThrough(self::REPORTS);
        $report = $a->safetyReports()->firstOrFail();
        $this->release($a, $report);
        // Reproduce the original import's result: only one report was retained.
        IntakeSafetyReport::where('id', '!=', $report->id)->delete();
        app(LaminitisSafety::class)->project($horse->fresh(), touch: false);
        $saved = $report->fresh()->getRawOriginal();
        $answers = $this->rows('intake_answers');
        $second = Horse::create(['owner_id' => $a->user_id, 'name' => 'Missing hold']);
        $other = IntakeBooking::create(['user_id' => $a->user_id, 'horse_id' => $second->id]);
        $this->answer($other, 'hoefbevangenheid', 'Ja, mijn paard is momenteel hoefbevangen');
        $this->assertNull($second->fresh()->laminitis_safety);

        $this->upgradeThrough(self::REPAIR);
        $this->assertSame($saved, $report->fresh()->getRawOriginal());
        $this->assertSame(3, $horse->safetyReports()->count());
        $this->assertSame(1, $second->safetyReports()->count());
        $this->assertHeld($horse);
        $this->assertHeld($second);
        foreach ($answers as $id => $row) {
            $this->assertEquals($row, $this->rows('intake_answers')[$id]);
        }
        $saved = $this->rows('intake_safety_reports');
        $projection = $horse->fresh()->laminitis_safety;
        (require database_path('migrations/'.self::REPAIR))->up();
        $this->assertEquals($saved, $this->rows('intake_safety_reports'));
        $this->assertSame($projection, $horse->fresh()->laminitis_safety);
    }

    public function test_history_keeps_other_intake_holds_and_releases_without_reviving_superseded_episodes(): void
    {
        [$horse, $a, $b, $c] = $this->fixtures();
        $this->upgradeThrough(self::RULES);
        $config = IntakeQuestionnaire::firstOrFail()->safety_config;
        $base = ['kind' => 'acute', 'configuration' => $config, 'requirements' => $config['recovery']['require'],
            'reported_at' => '2026-10-19T12:00:00Z'];
        $releasedA = $base + ['episode_id' => (string) Str::uuid(), 'booking_id' => $a->id,
            'released_at' => '2026-10-20T12:00:00Z', 'confirmations' => ['hoefbevangenheid-acute-fase-voorbij' => 'Ja', 'hoefbevangenheid-stabiel' => 'Ja']];
        $pendingC = $base + ['episode_id' => (string) Str::uuid(), 'booking_id' => $c->id];
        $legacy = $base + ['episode_id' => (string) Str::uuid(), 'booking_id' => $b->id,
            'history' => [array_diff_key($releasedA, ['released_at' => true, 'confirmations' => true]), $releasedA, $pendingC]];
        $horse->forceFill(['laminitis_safety' => $legacy])->save();
        $c->answers()->delete();
        $this->upgradeThrough(self::REPAIR);
        $reports = $horse->safetyReports()->get()->keyBy('intake_booking_id');
        $this->assertCount(3, $reports);
        $this->assertSame($releasedA, array_intersect_key($reports[$a->id]->state, $releasedA));
        $this->assertSame($pendingC, array_intersect_key($reports[$c->id]->state, $pendingC));
        $this->assertSame($legacy, array_intersect_key($reports[$b->id]->state, $legacy));
        $this->release($b, $reports[$b->id]);
        $this->assertHeld($horse);
        $this->release($b, $reports[$c->id]);
        $this->assertFalse(app(LaminitisSafety::class)->blocked($horse->fresh()));
        $this->assertSame(0, $c->answers()->count());
    }

    public function test_upgrade_preserves_distinct_concerns_in_one_intake_and_recovers_legacy_requirements(): void
    {
        [$horse, $a, $b, $c] = $this->fixtures();
        $b->answers()->delete();
        $c->answers()->delete();
        $this->upgradeThrough(self::RULES);
        $config = IntakeQuestionnaire::firstOrFail()->safety_config;
        // History predates requirement snapshots; today's answer is acute, so
        // it cannot supply the older unresolved concern's extra requirement.
        $unresolved = ['episode_id' => (string) Str::uuid(), 'booking_id' => $a->id,
            'kind' => 'unresolved', 'reported_at' => '2026-10-18T12:00:00Z'];
        $acute = ['episode_id' => (string) Str::uuid(), 'booking_id' => $a->id, 'kind' => 'acute',
            'configuration' => $config, 'requirements' => $config['recovery']['require'], 'reported_at' => '2026-10-19T12:00:00Z'];
        $superseded = array_replace($acute, ['episode_id' => (string) Str::uuid(), 'reported_at' => '2026-10-18T13:00:00Z']);
        $legacy = $acute + ['history' => [$unresolved, $superseded]];
        $horse->forceFill(['laminitis_safety' => $legacy])->save();
        $answers = $this->rows('intake_answers');
        $this->upgradeThrough(self::DISTINCT);
        $reports = $a->safetyReports()->get()->keyBy(fn ($r) => $r->state['kind']);
        $this->assertCount(2, $reports);
        $this->assertSame($acute['episode_id'], $reports['acute']->state['episode_id']);
        $this->assertSame($legacy['history'], $reports['acute']->state['history']);
        $this->assertSame($unresolved['episode_id'], $reports['unresolved']->state['episode_id']);
        $this->assertSame(['Ja'], $reports['unresolved']->state['requirements']['hoefbevangenheid-hersteld']);
        $this->release($a, $reports['acute']);
        $this->assertHeld($horse);
        try {
            app(LaminitisSafety::class)->release($a, $unresolved['episode_id'], $a->user_id,
                ['hoefbevangenheid-acute-fase-voorbij' => 'Ja', 'hoefbevangenheid-stabiel' => 'Ja']);
            $this->fail('The historical concern still requires its own recovery confirmation');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('laminitis', $e->errors());
        }
        $this->release($a, $reports['unresolved']);
        $this->assertFalse(app(LaminitisSafety::class)->blocked($horse->fresh()));
        $saved = $this->rows('intake_safety_reports');
        (require database_path('migrations/'.self::DISTINCT))->up();
        $this->assertEquals($saved, $this->rows('intake_safety_reports'));
        $this->assertEquals($answers, $this->rows('intake_answers'));
    }

    public function test_new_repair_retains_same_kind_with_different_pinned_rules_without_changing_existing_reports(): void
    {
        [$horse, $a, $b, $c] = $this->fixtures();
        $b->answers()->delete();
        $c->answers()->delete();
        $this->upgradeThrough(self::RULES);
        $config = IntakeQuestionnaire::firstOrFail()->safety_config;
        $older = $config;
        $older['recovery']['require']['hoefbevangenheid-stabiel'] = ['Previously configured yes'];
        $prior = ['episode_id' => (string) Str::uuid(), 'booking_id' => $a->id, 'kind' => 'acute',
            'configuration' => $older, 'requirements' => $older['recovery']['require']];
        $legacy = ['episode_id' => (string) Str::uuid(), 'booking_id' => $a->id, 'kind' => 'acute',
            'configuration' => $config, 'requirements' => $config['recovery']['require'], 'history' => [$prior]];
        $horse->forceFill(['laminitis_safety' => $legacy])->save();
        $this->upgradeThrough(self::REPAIR);
        $head = $a->safetyReports()->get()->first(fn ($r) => $r->state['episode_id'] === $legacy['episode_id']);
        // Simulate the already-applied faulty backfill retaining only the head.
        IntakeSafetyReport::where('id', '!=', $head->id)->delete();
        app(LaminitisSafety::class)->project($horse->fresh(), touch: false);
        $saved = $head->fresh()->getRawOriginal();
        $answers = $this->rows('intake_answers');
        $this->upgradeThrough(self::DISTINCT);
        $this->assertCount(2, $a->safetyReports()->get());
        $this->assertSame($saved, $head->fresh()->getRawOriginal());
        $restored = $a->safetyReports()->where('id', '!=', $head->id)->firstOrFail();
        $this->assertSame($prior, array_intersect_key($restored->state, $prior));
        $this->release($a, $head);
        $this->assertHeld($horse);
        $this->release($a, $restored);
        $this->assertFalse(app(LaminitisSafety::class)->blocked($horse->fresh()));
        $saved = $this->rows('intake_safety_reports');
        (require database_path('migrations/'.self::DISTINCT))->up();
        $this->assertEquals($saved, $this->rows('intake_safety_reports'));
        $this->assertEquals($answers, $this->rows('intake_answers'));
    }

    public function test_upgrade_clears_obsolete_answers_reopens_the_intake_and_repairs_historical_recovery_choices(): void
    {
        [$horse, $a, $b, $c] = $this->fixtures();
        $this->upgradeThrough(self::RULES);
        $config = IntakeQuestionnaire::firstOrFail()->safety_config;
        $older = $config;
        $older['recovery']['require']['hoefbevangenheid-stabiel'] = ['Previously configured yes'];
        $prior = ['episode_id' => (string) Str::uuid(), 'booking_id' => $a->id, 'kind' => 'acute',
            'configuration' => $older, 'requirements' => $older['recovery']['require']];
        $horse->forceFill(['laminitis_safety' => $prior])->save();
        $this->answer($a, 'hoefbevangenheid-stabiel', 'Previously configured yes');
        $a->forceFill(['submitted_at' => now(), 'completed_at' => now()])->save();
        $before = $a->answers()->where('field_id', '!=', 'hoefbevangenheid-stabiel')->get()->toArray();
        $this->upgradeThrough(self::DISTINCT); // Also exercise an already-migrated database.
        $this->upgradeThrough(self::OBSOLETE);
        $report = $a->safetyReports()->firstOrFail();
        $state = $report->state;
        $this->assertSame($prior['episode_id'], $state['episode_id']);
        $this->assertSame(['Ja'], $state['requirements']['hoefbevangenheid-stabiel']);
        $this->assertContains('Ja', collect($state['recovery_fields'])->firstWhere('id', 'hoefbevangenheid-stabiel')['options']);
        $this->assertSame(0, $a->answers()->where('field_id', 'hoefbevangenheid-stabiel')->count());
        $this->assertEquals($before, $a->answers()->get()->toArray());
        $this->assertNull($a->fresh()->submitted_at);
        $this->assertNull($a->fresh()->completed_at);
        $this->assertHeld($horse);
        $this->release($a->fresh(), $report);
        $this->assertHeld($horse); // The other intakes still have their own concerns.
        $saved = $this->rows('intake_safety_reports');
        (require database_path('migrations/'.self::OBSOLETE))->up();
        $this->assertEquals($saved, $this->rows('intake_safety_reports'));
    }
    public function test_recovery_replacement_preserves_answers_and_historical_reports_and_migrates_blocking_messages(): void
    {
        [$horse, $a, $b, $c, $safe] = $this->fixtures();
        $this->upgradeThrough(self::OBSOLETE);
        $answers = $this->rows('intake_answers');
        $reports = $this->rows('intake_safety_reports');
        $this->upgradeThrough(self::ANSWER_BLOCKS);
        $this->assertNull(IntakeQuestionnaire::firstOrFail()->safety_config);
        $this->assertEquals($answers, $this->rows('intake_answers'));
        $this->assertEquals($reports, $this->rows('intake_safety_reports'));
        $blocks = app(\App\Support\IntakeSubmissionBlocks::class);
        $this->assertNotEmpty($blocks->forBooking($a));
        $this->assertNotEmpty($blocks->forBooking($b));
        $this->assertNotEmpty($blocks->forBooking($c));
        $this->assertSame([], $blocks->forBooking($safe));
        $this->assertFalse(\App\Models\IntakeField::where('key', 'hoefbevangenheid-stabiel')->firstOrFail()->active);
        $this->assertTrue(\App\Models\IntakeField::where('key', 'hoefbevangenheid-hersteld')->firstOrFail()->active);
        $saved = $this->rows('intake_fields');
        (require database_path('migrations/'.self::ANSWER_BLOCKS))->up();
        $this->assertEquals($saved, $this->rows('intake_fields'));
    }
}
