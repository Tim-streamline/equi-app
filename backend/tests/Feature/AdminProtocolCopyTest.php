<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\DoseBuildup;
use App\Models\Horse;
use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use App\Models\Protocol;
use App\Models\ProtocolAdvice;
use App\Models\ProtocolPhaseSupplement;
use App\Models\ProtocolTemplate;
use App\Models\Supplement;
use App\Models\Therapist;
use App\Models\User;
use App\Models\VoedingAdvies;
use App\Support\ProtocolCopier;
use App\Support\ProtocolDoseBuildup;
use App\Support\ProtocolTiming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class AdminProtocolCopyTest extends TestCase
{
    use RefreshDatabase;

    private Protocol $source;

    private User $owner;

    private Horse $target;

    private Supplement $buildupSupplement;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = AdminUser::create(['name' => 'Copier', 'email' => 'protocol-copy@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $this->actingAs($admin, 'admin');

        $sourceOwner = User::factory()->create(['name' => 'Bron Eigenaar']);
        $sourceHorse = Horse::create(['name' => 'Bron', 'owner_id' => $sourceOwner->id, 'weight_kg' => 600, 'status' => 'active']);
        $sourceIntake = IntakeBooking::create(['horse_id' => $sourceHorse->id, 'user_id' => $sourceOwner->id, 'submitted_at' => now(), 'intake_status' => 'submitted', 'status' => 'pending']);
        $sourceIntake->forceFill(['started_at' => now()->subDays(2), 'completed_at' => now(), 'last_activity_at' => now()->subDay()->startOfSecond()])->save();

        $template = ProtocolTemplate::create(['name' => 'Herstel']);
        $start = $template->phases()->create(['name' => 'Opstart', 'description' => 'Rustig beginnen', 'order' => 0, 'required' => true]);
        $buildUp = $template->phases()->create(['name' => 'Opbouw', 'order' => 1, 'required' => false]);
        foreach ([$start, $buildUp] as $phase) {
            foreach (range(1, 3) as $n) {
                $phase->weeks()->create(['number' => $n]);
            }
        }
        $automatic = $start->supplements()->create(['name' => 'Lijnzaad', 'dosis_type' => 'per_600_kg', 'dosis' => 100, 'unit' => 'g', 'supplement_type' => 'kruid', 'add_by_default' => true]);
        $this->buildupSupplement = $start->supplements()->create(['name' => 'Heemst', 'dosis_type' => 'per_600_kg', 'dosis' => 40, 'unit' => 'g', 'supplement_type' => 'kruid', 'add_by_default' => true, 'dose_buildup_id' => DoseBuildup::SEVEN_DAYS_ID]);
        $manual = $buildUp->supplements()->create(['name' => 'Kruidenmix', 'dosis_type' => 'per_600_kg', 'dosis' => 10, 'unit' => 'g', 'supplement_type' => 'kruid', 'add_by_default' => false]);
        $therapist = Therapist::create(['name' => 'Therapeut']);
        $voeding = VoedingAdvies::create(['title' => 'Kleine porties', 'description' => 'Verdeel het hooi.']);

        $this->post('/admin/protocols', [
            'horse_id' => $sourceHorse->id, 'protocol_template_id' => $template->id, 'therapist_id' => $therapist->id,
            'title' => 'Herstelprotocol Bron', 'status' => 'paused', 'published' => true,
            'customer_settings' => ['use_target_weight' => false],
            'analysis' => ['cause' => 'Oorzaak bij Bron', 'summary' => 'Bron herstelt rustig.', 'observations' => ['Let op de mest van Bron.']], 'advice' => [],
            'voeding_advies_ids' => [$voeding->id], 'management_advies_ids' => [], 'beweging_advies_ids' => [],
            'phases' => [
                ['protocol_template_phase_id' => $start->id, 'client_key' => 'start', 'week_count' => 3, 'supplements' => [
                    ['supplement_id' => $automatic->id, 'dosage_mode' => 'automatic', 'aantal_per_week' => 7, 'instructions' => 'Weken.', 'week_numbers' => [1, 3]],
                    ['supplement_id' => $this->buildupSupplement->id, 'dosage_mode' => 'automatic', 'aantal_per_week' => 7, 'week_numbers' => [2]],
                ]],
                ['protocol_template_phase_id' => $buildUp->id, 'client_key' => 'opbouw', 'week_count' => 2, 'supplements' => [
                    ['supplement_id' => $manual->id, 'dosage_mode' => 'manual', 'dosage' => 'Een schep', 'aantal_per_week' => 4, 'week_numbers' => [1, 2]],
                ]],
            ],
            'manual_advice' => [
                ['client_key' => 'm1', 'category' => 'management', 'title' => 'Paddock', 'description' => 'Afrasteren.', 'management_category' => 'environment', 'action' => 'do', 'applies_to' => 'phases', 'phase_keys' => ['opbouw']],
                ['client_key' => 'm2', 'category' => 'voeding', 'title' => 'Zout', 'description' => 'Zout erbij.', 'management_category' => null, 'action' => 'do', 'applies_to' => 'protocol', 'phase_keys' => []],
            ],
        ])->assertSessionHasNoErrors();

        $this->source = $sourceIntake->protocol()->firstOrFail();
        $this->assertSame('100 g', $this->source->phases()->first()->supplements()->where('name', 'Lijnzaad')->value('dosage'));
        ProtocolAdvice::create(['analysis_id' => $this->source->analysis->id, 'icon_key' => 'leaf', 'title' => 'Kaart', 'body' => 'Legacy kaart', 'order' => 0]);
        DB::table('protocols')->where('id', $this->source->id)->update(['customer_settings' => json_encode([
            'weight_kg' => 600, 'use_target_weight' => true, 'target_weight_kg' => 550, 'sugar' => 'laag', 'weekly_update_day' => 3,
            'feed_overrides' => [['id' => str_repeat('a', 64), 'status' => 'stop', 'note' => 'Bron-specifiek']],
            'nutrition_advice' => [['id' => $voeding->id, 'instruction' => 'Persoonlijke instructie']],
        ])]);
        // Archived after selection: the protocol keeps its snapshot, and so does the copy.
        $voeding->update(['archived_at' => now()]);
        $this->source->refresh();

        // One of the team's own test users: the only other owner that receives the source intake.
        $this->owner = User::factory()->create(['name' => 'Doel Eigenaar', 'email' => 'doel@example.com', 'is_test_user' => true]);
        $this->target = Horse::create(['name' => 'Doel', 'owner_id' => $this->owner->id, 'weight_kg' => 300, 'status' => 'active']);
    }

    public function test_copies_the_structure_to_a_new_draft_that_uses_the_same_intake(): void
    {
        // The review of the source intake is the reviewer's, not the copy's.
        $this->source->intake->forceFill(['accepted_triggers' => ['laminitis'], 'review_notes' => 'Beoordeeld bij Bron'])->save();
        $sourceCounts = $this->counts($this->source);
        $before = Protocol::count();
        $sourceIntake = $this->source->intake()->sole();
        $intakeBefore = [IntakeBooking::count(), $sourceIntake->getAttributes()];

        $response = $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new']);

        $copy = Protocol::where('horse_id', $this->target->id)->sole();
        $response->assertRedirect("/admin/protocols/{$copy->id}")->assertSessionHas('success', fn ($message) => str_contains($message, 'Doel (Doel Eigenaar)') && ! str_contains($message, 'Let op')
            && str_contains($message, 'De kopie gebruikt dezelfde intake als het origineel. Je kunt de kopie direct aanpassen.'));
        $this->assertSame($before + 1, Protocol::count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->published_at);
        $this->assertNull($copy->started_at);
        $this->assertSame('Herstelprotocol Bron', $copy->title);
        $this->assertSame($this->source->protocol_template_id, $copy->protocol_template_id);
        $this->assertSame('Herstel', $copy->protocol_template_name);
        $this->assertSame($this->source->therapist_id, $copy->therapist_id);
        // Presentation and advice overrides travel with the copy; the source horse's weight and feeds do not.
        $this->assertEquals(['sugar' => 'laag', 'weekly_update_day' => 3, 'nutrition_advice' => $this->source->customer_settings['nutrition_advice']], $copy->customer_settings);

        // The copy uses the intake of the source: no second intake exists, and nothing of that intake changes,
        // including its review (accepted triggers and notes stay the reviewer's).
        $this->assertSame($sourceIntake->id, $copy->intake_booking_id);
        $this->assertTrue($copy->intake->is($sourceIntake));
        $this->assertSame($this->source->id, $copy->intake->protocol_id);
        $this->assertFalse(IntakeBooking::where('protocol_id', $copy->id)->exists());
        $this->assertFalse(IntakeBooking::where('horse_id', $this->target->id)->orWhere('user_id', $this->owner->id)->exists());
        $this->assertEquals($intakeBefore, [IntakeBooking::count(), $sourceIntake->fresh()->getAttributes()]);
        $copy->assertIntakeSubmitted();

        $this->assertSame(array_replace($sourceCounts, ['analysis' => 0, 'cards' => 0]), $this->counts($copy));
        $this->assertSame(1, $sourceCounts['cards']);
        $this->assertNull($copy->analysis()->first());

        $phases = $copy->phases()->with('weeks', 'supplements.weeks')->get();
        $this->assertSame(['Opstart', 'Opbouw'], $phases->pluck('title')->all());
        $this->assertSame('Rustig beginnen', $phases[0]->description);
        $copyWeekIds = $phases->flatMap->weeks->pluck('id');
        foreach ($phases->flatMap->supplements as $selection) {
            $this->assertNotEmpty($selection->weeks);
            $this->assertEmpty($selection->weeks->pluck('protocol_phase_week_id')->diff($copyWeekIds));
        }
        $lijnzaad = $phases[0]->supplements->firstWhere('name', 'Lijnzaad');
        $this->assertSame([1, 3], $lijnzaad->weeks->map(fn ($week) => $phases[0]->weeks->firstWhere('id', $week->protocol_phase_week_id)->number)->sort()->values()->all());
        $this->assertSame('Weken.', $lijnzaad->instructions);
        // Recalculated for the 300 kg target horse, including the structured dose buildup reads.
        $this->assertSame('50 g', $lijnzaad->dosage);
        $this->assertSame(50.0, $lijnzaad->full_dose_amount);
        $heemst = $phases[0]->supplements->firstWhere('name', 'Heemst');
        $this->assertSame('20 g', $heemst->dosage);
        $this->assertSame(20.0, $heemst->full_dose_amount);
        $this->assertSame(0.25, $heemst->dose_buildup['start_dose_ratio']);
        $this->assertSame($this->source->phases()->first()->supplements()->where('name', 'Heemst')->sole()->dose_buildup, $heemst->dose_buildup);
        $this->assertSame('Een schep', $phases[1]->supplements->sole()->dosage);

        $this->assertSame(['Kleine porties'], $copy->voedingAdviezen()->pluck('title')->all());
        $paddock = $copy->manualAdviezen()->with('phases')->where('title', 'Paddock')->sole();
        $this->assertSame([$phases[1]->id], $paddock->phases->pluck('id')->all());

        $this->assertSame($sourceCounts, $this->counts($this->source->fresh()));
        $this->assertNotNull($this->source->fresh()->published_at);

        $log = AuditLog::where('action', 'copied')->sole();
        $this->assertSame($copy->id, $log->target_id);
        $this->assertSame($this->source->id, $log->after['source_protocol_id']);
        $this->assertSame('new', $log->after['destination']);
        $this->assertSame($sourceIntake->id, $log->after['intake_booking_id']);
        $this->assertTrue(AuditLog::where('action', 'created')->where('target_id', $copy->id)->exists());

        $this->get("/admin/protocols/{$copy->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Protocols/Show')->where('protocol.id', $copy->id));
    }

    public function test_analysis_and_its_cards_are_copied_only_on_request(): void
    {
        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new', 'include_analysis' => true])
            ->assertSessionHasNoErrors();

        $analysis = Protocol::where('horse_id', $this->target->id)->sole()->analysis()->with('advice')->sole();
        $this->assertSame('Oorzaak bij Bron', $analysis->cause);
        $this->assertSame('Bron herstelt rustig.', $analysis->summary);
        $this->assertSame(['Kaart'], $analysis->advice->pluck('title')->all());
        $this->assertNotSame($this->source->analysis->id, $analysis->id);
    }

    public function test_fills_the_chosen_unconfigured_draft_and_keeps_its_intake(): void
    {
        $intake = IntakeBooking::create(['horse_id' => $this->target->id, 'user_id' => $this->owner->id, 'submitted_at' => now(), 'status' => 'pending']);
        $intake->answers()->create(['field_id' => 'gewicht', 'section_id' => 'paard', 'value' => json_encode(450)]);
        $draft = $intake->protocol()->firstOrFail();
        $before = Protocol::count();

        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'draft', 'draft_id' => $draft->id])
            ->assertRedirect("/admin/protocols/{$draft->id}");

        $this->assertSame($before, Protocol::count());
        $draft->refresh();
        $this->assertSame($this->source->protocol_template_id, $draft->protocol_template_id);
        $this->assertSame('Herstelprotocol Bron', $draft->title);
        $this->assertSame('draft', $draft->status);
        $this->assertSame($intake->id, $draft->intake()->sole()->id);
        $this->assertSame(1, $draft->intake->answers()->count());
        // The submitted intake weight of the target horse drives the automatic dose.
        $this->assertSame('75 g', $draft->phases()->first()->supplements()->where('name', 'Lijnzaad')->value('dosage'));
        $this->assertSame('draft', AuditLog::where('action', 'copied')->sole()->after['destination']);
        $this->assertSame($intake->id, AuditLog::where('action', 'copied')->sole()->after['intake_booking_id']);
        $this->assertTrue(AuditLog::where('action', 'updated')->where('target_id', $draft->id)->exists());
    }

    public function test_a_reused_draft_keeps_its_own_horse_settings_and_uses_its_weight(): void
    {
        $draft = $this->draftWithOwnSettings();

        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'draft', 'draft_id' => $draft->id])
            ->assertSessionHasNoErrors();

        $settings = $draft->fresh()->customer_settings;
        $this->assertSame(480, $settings['weight_kg']);
        $this->assertSame([['id' => str_repeat('b', 64), 'status' => 'continue', 'note' => 'Doel-specifiek']], $settings['feed_overrides']);
        $this->assertArrayNotHasKey('target_weight_kg', $settings);
        $this->assertSame('laag', $settings['sugar']);
        $this->assertSame('80 g', $draft->phases()->first()->supplements()->where('name', 'Lijnzaad')->value('dosage'));
    }

    public function test_a_failure_leaves_a_reused_draft_untouched(): void
    {
        $draft = $this->draftWithOwnSettings();
        $original = $draft->fresh()->only(['title', 'protocol_template_id', 'protocol_template_name', 'therapist_id', 'customer_settings', 'status', 'published_at']);
        $intakeId = $draft->intake()->value('id');
        $logs = AuditLog::count();
        $this->mock(ProtocolTiming::class)->shouldReceive('synchronize')->andThrow(new RuntimeException('boom'));

        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'draft', 'draft_id' => $draft->id])
            ->assertSessionHasErrors('copy');

        $draft->refresh();
        $this->assertSame($original, $draft->only(array_keys($original)));
        $this->assertSame($intakeId, $draft->intake()->value('id'));
        $this->assertSame(0, $draft->phases()->count());
        $this->assertSame(0, $draft->voedingAdviezen()->count() + $draft->manualAdviezen()->count());
        $this->assertSame($logs, AuditLog::count());
    }

    public function test_unknown_target_weight_leaves_automatic_doses_empty_and_reports_them(): void
    {
        $this->target->update(['weight_kg' => null]);

        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new'])
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'Let op: 2 automatische'));

        $copy = Protocol::where('horse_id', $this->target->id)->sole();
        $automatic = ProtocolPhaseSupplement::whereIn('protocol_phase_id', $copy->phases()->select('id'))->where('dosage_mode', 'automatic')->get();
        $this->assertCount(2, $automatic);
        foreach ($automatic as $selection) {
            $this->assertNull($selection->dosage);
            $this->assertNull($selection->full_dose_amount);
            $this->assertNull($selection->full_dose_unit);
        }
        // The buildup settings stay, but there is no full dose to build up from yet.
        $heemst = $automatic->firstWhere('name', 'Heemst');
        $this->assertNotNull($heemst->dose_buildup);
        $this->assertNull(app(ProtocolDoseBuildup::class)->dose($heemst, 0));
        $this->assertNull(app(ProtocolDoseBuildup::class)->presentation($heemst, now()->toDateString())['currentDosage']);
        $this->get("/admin/protocols/{$copy->id}")->assertOk();
    }

    public function test_a_horse_with_an_active_protocol_can_receive_a_draft_copy(): void
    {
        $intake = IntakeBooking::create(['horse_id' => $this->target->id, 'user_id' => $this->owner->id, 'submitted_at' => now(), 'status' => 'pending']);
        $intake->protocol->update(['status' => 'active']);

        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['active', 'draft'], Protocol::where('horse_id', $this->target->id)->orderBy('status')->pluck('status')->all());
    }

    public function test_copies_to_the_source_protocols_own_horse_as_an_additional_draft(): void
    {
        $sourceCounts = $this->counts($this->source);

        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->source->horse->owner_id, 'horse_id' => $this->source->horse_id, 'destination' => 'new', 'include_analysis' => true])
            ->assertSessionHasNoErrors();

        $copy = Protocol::where('horse_id', $this->source->horse_id)->whereKeyNot($this->source->id)->sole();
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->published_at);
        $this->assertSame($this->source->intake()->sole()->id, $copy->intake()->sole()->id);
        $this->assertSame(1, IntakeBooking::where('horse_id', $this->source->horse_id)->count());
        $this->assertSame($sourceCounts, $this->counts($copy));
        $this->assertSame($sourceCounts, $this->counts($this->source->fresh()));
        // Recalculated for the same 600 kg horse.
        $this->assertSame('100 g', $copy->phases()->first()->supplements()->where('name', 'Lijnzaad')->value('dosage'));
        $this->assertSame($this->source->horse_id, AuditLog::where('action', 'copied')->sole()->after['target_horse_id']);
    }

    public function test_the_copy_gets_the_title_chosen_in_the_dialog(): void
    {
        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->source->horse->owner_id, 'horse_id' => $this->source->horse_id, 'destination' => 'new', 'title' => '  Herstelprotocol Bron, tweede ronde  '])
            ->assertSessionHasNoErrors();

        $this->assertSame('Herstelprotocol Bron, tweede ronde', Protocol::where('horse_id', $this->source->horse_id)->whereKeyNot($this->source->id)->sole()->title);
        $this->assertSame('Herstelprotocol Bron', $this->source->fresh()->title);

        // An empty title keeps the source title, and an over-long one is refused.
        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new', 'title' => ''])
            ->assertSessionHasNoErrors();
        $this->assertSame('Herstelprotocol Bron', Protocol::where('horse_id', $this->target->id)->sole()->title);
        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new', 'title' => str_repeat('x', 256)])
            ->assertSessionHasErrors('title');
    }

    public function test_filling_a_draft_of_the_source_protocols_own_horse_keeps_its_intake(): void
    {
        $this->sourceIntakeWithPhoto();
        $sourceOwnerId = $this->source->horse->owner_id;
        $draft = IntakeBooking::create(['horse_id' => $this->source->horse_id, 'user_id' => $sourceOwnerId])->protocol;
        $before = Protocol::count();

        $this->getJson("/admin/protocols/{$this->source->id}/copy-targets?to=same")->assertOk()
            ->assertJsonCount(1, 'horses')
            ->assertJsonPath('horses.0.id', $this->source->horse_id)
            ->assertJsonPath('horses.0.owner.id', $sourceOwnerId)
            ->assertJsonPath('horses.0.drafts.0.id', $draft->id)
            ->assertJsonPath('horses.0.drafts.0.intake_submitted', false)
            ->assertJsonPath('horses.0.protocols', fn ($protocols) => collect($protocols)->sortBy('id')->values()->all()
                === collect([['id' => $this->source->id, 'title' => 'Herstelprotocol Bron'], ['id' => $draft->id, 'title' => $draft->title]])->sortBy('id')->values()->all());

        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $sourceOwnerId, 'horse_id' => $this->source->horse_id, 'destination' => 'draft', 'draft_id' => $draft->id, 'title' => 'Herstel, vervolg'])
            ->assertRedirect("/admin/protocols/{$draft->id}");

        $this->assertSame($before, Protocol::count());
        $draft->refresh();
        $this->assertSame('Herstel, vervolg', $draft->title);
        $this->assertSame($this->source->protocol_template_id, $draft->protocol_template_id);
        $intake = $draft->intake()->sole();
        $this->assertNull($intake->submitted_at);
        $this->assertSame(0, $intake->answers()->count());
        $this->assertSame(0, IntakeAttachment::where('booking_id', $intake->id)->count());
        $this->assertSame(array_replace($this->counts($this->source), ['analysis' => 0, 'cards' => 0]), $this->counts($draft));
        $this->assertSame($intake->id, AuditLog::where('action', 'copied')->sole()->after['intake_booking_id']);
        $this->assertNotSame($this->source->intake_booking_id, $draft->intake_booking_id);
    }

    public function test_the_source_protocol_itself_is_never_a_draft_destination(): void
    {
        $original = $this->source->only(['title', 'protocol_template_id', 'status', 'customer_settings']);
        $counts = $this->counts($this->source);

        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->source->horse->owner_id, 'horse_id' => $this->source->horse_id, 'destination' => 'draft', 'draft_id' => $this->source->id])
            ->assertSessionHasErrors('draft_id');

        $this->assertSame($original, $this->source->fresh()->only(array_keys($original)));
        $this->assertSame($counts, $this->counts($this->source->fresh()));
        $this->assertFalse(AuditLog::where('action', 'copied')->exists());
    }

    public function test_a_same_horse_copy_drops_the_weight_and_feed_overrides_and_doses_from_the_intake(): void
    {
        // The source has its own weight (600), target weight (550) and feed overrides; its intake says 450 kg.
        $this->sourceIntakeWithPhoto();
        $this->assertSame(600, $this->source->customer_settings['weight_kg']);
        $this->assertNotEmpty($this->source->customer_settings['feed_overrides']);

        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->source->horse->owner_id, 'horse_id' => $this->source->horse_id, 'destination' => 'new'])
            ->assertSessionHasNoErrors();

        // Intended: horse-specific settings stay with the original, also on the same horse.
        $copy = Protocol::where('horse_id', $this->source->horse_id)->whereKeyNot($this->source->id)->sole();
        $this->assertSame([], array_intersect_key($copy->customer_settings ?? [], array_flip(ProtocolCopier::HORSE_SPECIFIC_SETTINGS)));
        $this->assertSame('laag', $copy->customer_settings['sugar']);
        $this->assertSame('75 g', $copy->phases()->first()->supplements()->where('name', 'Lijnzaad')->value('dosage'));
        $this->assertSame(600, $this->source->fresh()->customer_settings['weight_kg']);
    }

    public function test_an_existing_draft_keeps_its_own_untouched_intake(): void
    {
        $this->sourceIntakeWithPhoto();
        // Even a never opened intake stays as it is: its owner may be answering it on a device that is offline.
        $draft = IntakeBooking::create(['horse_id' => $this->target->id, 'user_id' => $this->owner->id, 'status' => 'confirmed'])->protocol;
        $intake = $draft->intake()->sole();
        $state = fn () => $intake->fresh()->only(['status', 'intake_status', 'started_at', 'submitted_at', 'completed_at', 'last_activity_at', 'accepted_triggers', 'updated_at']);
        $before = $state();

        $this->getJson("/admin/protocols/{$this->source->id}/copy-targets?to=test")->assertOk()
            ->assertJsonPath('horses.0.drafts.0.id', $draft->id)
            ->assertJsonPath('horses.0.drafts.0.intake_submitted', false)
            ->assertJsonMissingPath('horses.0.drafts.0.intake_empty');

        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'draft', 'draft_id' => $draft->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'Het concept houdt zijn eigen intake.'));

        $this->assertNotNull($draft->fresh()->protocol_template_id);
        $this->assertEquals($before, $state());
        $this->assertSame('confirmed', $intake->fresh()->status);
        $this->assertSame(0, $intake->answers()->count());
        $this->assertSame(1, IntakeAttachment::count());
        $this->assertSame(["intake/{$this->source->intake->id}/huid.png"], Storage::disk('local')->allFiles('intake'));
        $this->assertSame($intake->id, $draft->fresh()->intake_booking_id);
    }

    public function test_only_the_own_horse_and_horses_of_test_users_receive_a_copy(): void
    {
        $this->sourceIntakeWithPhoto();
        $customer = User::factory()->create(['name' => 'Andere Klant']);
        $foreign = Horse::create(['name' => 'Vreemd', 'owner_id' => $customer->id, 'weight_kg' => 300, 'status' => 'active']);
        // Also not another horse of the protocol's own user.
        $second = Horse::create(['name' => 'Tweede', 'owner_id' => $this->source->horse->owner_id, 'weight_kg' => 300, 'status' => 'active']);
        $before = [Protocol::count(), IntakeBooking::count(), IntakeAttachment::count(), AuditLog::count()];

        foreach ([$foreign, $second] as $horse) {
            $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $horse->owner_id, 'horse_id' => $horse->id, 'destination' => 'new'])
                ->assertSessionHasErrors(['horse_id' => 'Een protocol kan alleen worden gekopieerd naar het eigen paard of naar een paard van een testgebruiker.']);
        }
        $this->assertSame($before, [Protocol::count(), IntakeBooking::count(), IntakeAttachment::count(), AuditLog::count()]);

        // Once the customer is marked as a test user, the same copy is accepted.
        $customer->forceFill(['is_test_user' => true])->save();
        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $customer->id, 'horse_id' => $foreign->id, 'destination' => 'new'])
            ->assertSessionHasNoErrors();
        $this->assertSame(5, Protocol::where('horse_id', $foreign->id)->sole()->intake()->sole()->answers()->count());
    }

    public function test_only_admins_mark_a_test_user(): void
    {
        $customer = User::factory()->create(['name' => 'Klant', 'email' => 'klant@example.com']);
        $profile = ['name' => 'Klant', 'email' => 'klant@example.com', 'notifications_on' => true];
        $url = "/admin/users/{$customer->id}/test-user";

        $this->post($url, [])->assertSessionHasErrors('is_test_user');
        $this->post($url, ['is_test_user' => true])->assertSessionHasNoErrors();
        $this->assertTrue($customer->fresh()->is_test_user);
        $log = AuditLog::where('target_id', $customer->id)->where('action', 'updated')->sole();
        $this->assertSame([false, true], [$log->before['is_test_user'], $log->after['is_test_user']]);
        Horse::create(['name' => 'Klantpaard', 'owner_id' => $customer->id, 'status' => 'active']);
        $this->getJson("/admin/protocols/{$this->source->id}/copy-targets?to=test")->assertOk()
            ->assertJsonPath('horses', fn ($horses) => collect($horses)->pluck('name')->all() === ['Doel', 'Klantpaard']);

        // The profile form never carries the setting, whatever it sends.
        $this->put("/admin/users/{$customer->id}", ['name' => 'Klant B', 'is_test_user' => false] + $profile)->assertSessionHasNoErrors();
        $this->assertSame('Klant B', $customer->fresh()->name);
        $this->assertTrue($customer->fresh()->is_test_user);

        $this->actingAs(AdminUser::create(['name' => 'Support', 'email' => 'support-copy@example.com', 'password' => 'password', 'role' => 'support', 'active' => true]), 'admin');
        $this->post($url, ['is_test_user' => false])->assertForbidden();
        $this->assertTrue($customer->fresh()->is_test_user);
        $other = User::factory()->create();
        $this->post("/admin/users/{$other->id}/test-user", ['is_test_user' => true])->assertForbidden();
        $this->put("/admin/users/{$other->id}", ['name' => $other->name, 'email' => $other->email, 'is_test_user' => true])->assertSessionHasNoErrors();
        $this->assertFalse($other->fresh()->is_test_user);

        $this->actingAs(AdminUser::where('email', 'protocol-copy@example.com')->sole(), 'admin');
        $this->post($url, ['is_test_user' => false])->assertSessionHasNoErrors();
        $this->assertFalse($customer->fresh()->is_test_user);
    }

    public function test_a_copy_for_a_test_user_uses_the_customers_intake_without_duplicating_it(): void
    {
        [$file, $sourceIntake] = $this->sourceIntakeWithPhoto();

        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new'])
            ->assertSessionHasNoErrors();

        $copy = Protocol::where('horse_id', $this->target->id)->sole();
        $this->assertSame($sourceIntake->id, $copy->intake_booking_id);
        // Nothing of the intake reaches the test user's account: it stays the customer's, with its one file.
        $this->assertSame([$sourceIntake->id], IntakeBooking::pluck('id')->all());
        $this->assertSame($this->source->horse->owner_id, $copy->intake->user_id);
        $this->assertSame(5, $sourceIntake->answers()->count());
        $this->assertSame([$file->id], IntakeAttachment::pluck('id')->all());
        $this->assertSame([$file->path], Storage::disk('local')->allFiles('intake'));
        // The intake's weight (450 kg) drives the automatic dose, not the test horse's 300 kg: the preview shows the
        // customer's doses.
        $this->assertSame('75 g', $copy->phases()->first()->supplements()->where('name', 'Lijnzaad')->value('dosage'));
    }

    public function test_the_intake_stays_with_its_own_user_after_a_horse_transfer(): void
    {
        $sourceOwner = $this->source->horse->owner;
        $newOwner = User::factory()->create();
        DB::table('horses')->where('id', $this->source->horse_id)->update(['owner_id' => $newOwner->id]);

        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $newOwner->id, 'horse_id' => $this->source->horse_id, 'destination' => 'new'])
            ->assertSessionHasNoErrors();

        $copy = Protocol::where('horse_id', $this->source->horse_id)->whereKeyNot($this->source->id)->sole();
        $this->assertSame($this->source->intake_booking_id, $copy->intake_booking_id);
        $this->assertSame($sourceOwner->id, $copy->intake->user_id);
        $this->assertFalse(IntakeBooking::where('user_id', $newOwner->id)->exists());
    }

    public function test_a_copy_waits_for_the_shared_intake_to_be_submitted(): void
    {
        $this->sourceIntakeWithPhoto();
        DB::table('intake_bookings')->where('id', $this->source->intake_booking_id)
            ->update(['submitted_at' => null, 'completed_at' => null, 'intake_status' => 'draft']);

        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'De kopie gebruikt dezelfde intake als het origineel. Aanpassen kan zodra die intake is verstuurd.'));

        $copy = Protocol::where('horse_id', $this->target->id)->sole();
        // An unsubmitted intake's weight does not count yet: the horse's own 300 kg drives the dose.
        $this->assertSame('50 g', $copy->phases()->first()->supplements()->where('name', 'Lijnzaad')->value('dosage'));
        $this->post("/admin/protocols/{$copy->id}/status", ['status' => 'active'])->assertSessionHasErrors('intake');

        // Submitting the one intake opens up the original and every copy.
        DB::table('intake_bookings')->where('id', $this->source->intake_booking_id)->update(['submitted_at' => now(), 'intake_status' => 'submitted']);
        $copy->fresh()->assertIntakeSubmitted();
        $this->source->fresh()->assertIntakeSubmitted();
    }

    public function test_a_cancelled_intake_cannot_carry_a_new_copy(): void
    {
        DB::table('intake_bookings')->where('id', $this->source->intake_booking_id)->update(['status' => 'cancelled']);
        $before = Protocol::count();

        $this->getJson("/admin/protocols/{$this->source->id}/copy-targets?to=same")->assertOk()->assertJsonPath('intake.cancelled', true);
        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new'])
            ->assertSessionHasErrors('destination');
        $this->assertSame($before, Protocol::count());

        // A draft with its own intake can still be filled.
        $draft = IntakeBooking::create(['horse_id' => $this->target->id, 'user_id' => $this->owner->id, 'submitted_at' => now()])->protocol;
        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'draft', 'draft_id' => $draft->id])
            ->assertSessionHasNoErrors();
    }

    public function test_an_unsubmitted_intake_nobody_can_submit_any_more_cannot_carry_a_new_copy(): void
    {
        DB::table('intake_bookings')->where('id', $this->source->intake_booking_id)->update(['submitted_at' => null, 'completed_at' => null, 'intake_status' => 'draft']);
        $newOwner = User::factory()->create();
        DB::table('horses')->where('id', $this->source->horse_id)->update(['owner_id' => $newOwner->id]);
        $before = Protocol::count();

        // Its user no longer owns the horse, and the new owner is not the intake's user.
        foreach ([[$newOwner->id, $this->source->horse_id], [$this->owner->id, $this->target->id]] as [$userId, $horseId]) {
            $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $userId, 'horse_id' => $horseId, 'destination' => 'new'])
                ->assertSessionHasErrors('destination');
        }
        $this->assertSame($before, Protocol::count());

        // Once it is submitted, or while its user still owns the horse, the copy is fine.
        DB::table('intake_bookings')->where('id', $this->source->intake_booking_id)->update(['submitted_at' => now(), 'intake_status' => 'submitted']);
        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $newOwner->id, 'horse_id' => $this->source->horse_id, 'destination' => 'new'])
            ->assertSessionHasNoErrors();
    }

    public function test_every_protocol_names_the_intake_it_uses(): void
    {
        // Created from the protocol side and from the intake side: both end up linked both ways.
        $fromProtocol = Protocol::create(['horse_id' => $this->target->id, 'title' => 'Nieuw', 'status' => 'draft']);
        $fromIntake = IntakeBooking::create(['horse_id' => $this->target->id, 'user_id' => $this->owner->id])->protocol;
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        foreach ([$fromProtocol->fresh(), $fromIntake->fresh(), $this->source->fresh()] as $protocol) {
            $own = IntakeBooking::where('protocol_id', $protocol->id)->sole();
            $this->assertSame($own->id, $protocol->intake_booking_id);
            $this->assertTrue($protocol->intake->is($own));
        }
    }

    public function test_deleting_a_copy_leaves_the_shared_intake_and_its_files(): void
    {
        [$file, $sourceIntake] = $this->sourceIntakeWithPhoto();
        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new'])->assertSessionHasNoErrors();
        $copy = Protocol::where('horse_id', $this->target->id)->sole();
        $sourceCounts = $this->counts($this->source);

        // "Protocol + intake" cannot remove an intake the original still uses.
        $this->post('/admin/protocols/bulk', ['action' => 'delete', 'ids' => [$copy->id], 'confirmed' => true])->assertSessionHasErrors('protocol');
        $this->assertNotNull($copy->fresh());
        $this->postJson('/admin/protocols/bulk', ['action' => 'delete_protocol', 'ids' => [$copy->id]])->assertJsonValidationErrors('confirmed');

        $this->post('/admin/protocols/bulk', ['action' => 'delete_protocol', 'ids' => [$copy->id], 'confirmed' => true])->assertSessionHasNoErrors();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertNull($copy->fresh());
        $this->assertSame(0, Protocol::where('horse_id', $this->target->id)->count());
        $this->assertSame($sourceIntake->id, $this->source->fresh()->intake_booking_id);
        $this->assertSame($this->source->id, $sourceIntake->fresh()->protocol_id);
        $this->assertSame(5, $sourceIntake->answers()->count());
        $this->assertSame([$file->id], IntakeAttachment::pluck('id')->all());
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame($sourceCounts, $this->counts($this->source->fresh()));
    }

    public function test_deleting_the_original_hands_its_intake_to_the_copy_on_the_same_horse(): void
    {
        [$file, $sourceIntake] = $this->sourceIntakeWithPhoto();
        // Only the protocol it belongs to changes (and with it the row's update time, which syncs that to the app).
        $state = fn () => \Illuminate\Support\Arr::except($sourceIntake->fresh()->getAttributes(), ['protocol_id', 'updated_at']);
        $before = $state();
        DB::table('protocol_purchases')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->source->horse->owner_id, 'horse_id' => $this->source->horse_id,
            'protocol_id' => $this->source->id, 'request_key' => (string) Str::uuid(), 'amount_cents' => 14700, 'currency' => 'EUR', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$this->owner, $this->target], [$this->source->horse->owner, $this->source->horse]] as [$owner, $horse]) {
            $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $owner->id, 'horse_id' => $horse->id, 'destination' => 'new'])->assertSessionHasNoErrors();
        }
        $sameHorse = Protocol::where('horse_id', $this->source->horse_id)->whereKeyNot($this->source->id)->sole();
        $testCopy = Protocol::where('horse_id', $this->target->id)->sole();
        $protocols = Protocol::count();

        $this->post('/admin/protocols/bulk', ['action' => 'delete_protocol', 'ids' => [$this->source->id], 'confirmed' => true])->assertSessionHasNoErrors();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        // The copy on the same horse now owns the intake; the copy on the test horse keeps using it. No new protocol.
        $this->assertNull($this->source->fresh());
        $this->assertSame($protocols - 1, Protocol::count());
        $this->assertSame($sameHorse->id, $sourceIntake->fresh()->protocol_id);
        $this->assertSame([$sourceIntake->id, $sourceIntake->id], [$sameHorse->fresh()->intake_booking_id, $testCopy->fresh()->intake_booking_id]);
        $this->assertEquals($before, $state());
        $this->assertSame(5, $sourceIntake->answers()->count());
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame($sameHorse->id, DB::table('protocol_purchases')->value('protocol_id'));
        $this->assertSame($this->counts($sameHorse), $this->counts($sameHorse->fresh()));
        $sameHorse->fresh()->assertIntakeSubmitted();
        $this->assertSame('Bulk verwijderen, intake bewaard', AuditLog::where('action', 'deleted')->where('target_id', $this->source->id)->sole()->reason);
    }

    public function test_deleting_the_only_protocol_of_a_horse_keeps_its_intake_with_a_new_empty_draft(): void
    {
        [$file, $sourceIntake] = $this->sourceIntakeWithPhoto();
        // Only a copy on a test user's horse uses the intake too: an intake stays with a protocol of its own horse.
        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new'])->assertSessionHasNoErrors();
        $testCopy = Protocol::where('horse_id', $this->target->id)->sole();

        $this->post('/admin/protocols/bulk', ['action' => 'delete_protocol', 'ids' => [$this->source->id], 'confirmed' => true])->assertSessionHasNoErrors();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertNull($this->source->fresh());
        $draft = Protocol::where('horse_id', $this->source->horse_id)->sole();
        $this->assertSame(['Persoonlijk protocol', 'draft', null, null], [$draft->title, $draft->status, $draft->protocol_template_id, $draft->published_at]);
        $this->assertSame(0, $draft->phases()->count());
        $this->assertSame($sourceIntake->id, $draft->intake_booking_id);
        $this->assertSame($draft->id, $sourceIntake->fresh()->protocol_id);
        $this->assertNotNull($sourceIntake->fresh()->submitted_at);
        $this->assertSame(5, $sourceIntake->answers()->count());
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame($sourceIntake->id, $testCopy->fresh()->intake_booking_id);
        $this->assertTrue(AuditLog::where('action', 'created')->where('target_id', $draft->id)->exists());
        // The new draft is an ordinary unconfigured draft: a later copy can fill it.
        $this->assertTrue(ProtocolCopier::reusableDrafts($this->source->horse->owner)->whereKey($draft->id)->exists());
    }

    public function test_a_protocol_and_its_intake_are_deleted_only_together_with_every_copy_that_uses_it(): void
    {
        [$file, $sourceIntake] = $this->sourceIntakeWithPhoto();
        foreach ([[$this->owner, $this->target], [$this->source->horse->owner, $this->source->horse]] as [$owner, $horse]) {
            $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $owner->id, 'horse_id' => $horse->id, 'destination' => 'new'])->assertSessionHasNoErrors();
        }
        $copies = Protocol::where('intake_booking_id', $sourceIntake->id)->whereKeyNot($this->source->id)->pluck('id');
        $this->assertCount(2, $copies);

        // On its own the original stays, with its intake: the copies still need it.
        $this->post('/admin/protocols/bulk', ['action' => 'delete', 'ids' => [$this->source->id], 'confirmed' => true])
            ->assertSessionHasErrors(['protocol' => 'De intake van protocol “Herstelprotocol Bron” wordt ook gebruikt door andere protocollen. Selecteer die ook, of kies “Protocol verwijderen”: dan blijft de intake bewaard.']);
        // With one copy left behind as well: nothing of the selection is deleted.
        $this->post('/admin/protocols/bulk', ['action' => 'delete', 'ids' => [$this->source->id, $copies[0]], 'confirmed' => true])->assertSessionHasErrors('protocol');
        $this->assertSame(3, Protocol::where('intake_booking_id', $sourceIntake->id)->count());
        Storage::disk('local')->assertExists($file->path);
        // The database refuses it too, whatever code deletes the protocol.
        try {
            DB::transaction(fn () => DB::table('protocols')->where('id', $this->source->id)->delete());
            $this->fail('The intake of a protocol was removed while copies use it.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertTrue($this->source->fresh()->exists);
        }

        // Selected together, in any order, they all go, and the intake with its file goes with the original.
        $this->post('/admin/protocols/bulk', ['action' => 'delete', 'ids' => [$this->source->id, ...$copies], 'confirmed' => true])->assertSessionHasNoErrors();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame(0, Protocol::whereIn('id', [$this->source->id, ...$copies])->count());
        $this->assertNull($sourceIntake->fresh());
        $this->assertSame(0, IntakeAttachment::count());
        Storage::disk('local')->assertMissing($file->path);
    }

    public function test_deleting_the_horse_also_removes_the_copies_that_use_its_intake(): void
    {
        $this->sourceIntakeWithPhoto();
        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new'])->assertSessionHasNoErrors();
        $copy = Protocol::where('horse_id', $this->target->id)->sole();
        $own = Protocol::create(['horse_id' => $this->target->id, 'title' => 'Eigen protocol', 'status' => 'draft']);

        app(\App\Support\HorseDeletion::class)->delete($this->source->horse);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        // The customer's intake is gone, and so is every protocol built on it, also on the test user's horse.
        $this->assertSame(0, IntakeBooking::where('horse_id', $this->source->horse_id)->count());
        $this->assertNull($copy->fresh());
        $this->assertNull($this->source->fresh());
        $this->assertNotNull($this->target->fresh());
        $this->assertNotNull($own->fresh());
    }

    public function test_a_new_protocol_can_be_added_to_an_existing_intake(): void
    {
        $intake = $this->source->intake()->sole();
        $before = [IntakeBooking::count(), $intake->getAttributes()];

        $response = $this->post("/admin/bookings/{$intake->id}/protocols");

        $added = Protocol::where('intake_booking_id', $intake->id)->whereKeyNot($this->source->id)->sole();
        $response->assertRedirect("/admin/protocols/{$added->id}/edit")->assertSessionHas('success');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        // An empty draft on the intake's horse that uses the intake; the intake itself is untouched and stays the
        // original protocol's.
        $this->assertSame([$this->source->horse_id, 'Persoonlijk protocol', 'draft', null, null], [$added->horse_id, $added->title, $added->status, $added->protocol_template_id, $added->published_at]);
        $this->assertEquals($before, [IntakeBooking::count(), $intake->fresh()->getAttributes()]);
        $this->assertSame($this->source->id, $intake->fresh()->protocol_id);
        $added->assertIntakeSubmitted();
        $this->assertTrue(AuditLog::where('action', 'created')->where('target_id', $added->id)->exists());
        $this->get("/admin/protocols/{$added->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Protocols/Edit')->where('protocol.id', $added->id)->where('protocol.intake.id', $intake->id)->etc());
        $this->get("/admin/bookings/{$intake->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Bookings/Show')
            ->where('protocols', fn ($protocols) => collect($protocols)->sortBy('id')->values()->all() === collect([
                ['id' => $this->source->id, 'title' => 'Herstelprotocol Bron', 'status' => 'paused', 'configured' => true, 'published' => true, 'horse' => null],
                ['id' => $added->id, 'title' => 'Persoonlijk protocol', 'status' => 'draft', 'configured' => false, 'published' => false, 'horse' => null],
            ])->sortBy('id')->values()->all())->etc());

        // It is an ordinary unconfigured draft: a copy can fill it, and it keeps using the intake.
        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->source->horse->owner_id, 'horse_id' => $this->source->horse_id, 'destination' => 'draft', 'draft_id' => $added->id, 'title' => 'Vervolgprotocol'])
            ->assertRedirect("/admin/protocols/{$added->id}");
        $this->assertSame(['Vervolgprotocol', $this->source->protocol_template_id, $intake->id], [$added->fresh()->title, $added->fresh()->protocol_template_id, $added->fresh()->intake_booking_id]);
        $this->assertSame(array_replace($this->counts($this->source), ['analysis' => 0, 'cards' => 0]), $this->counts($added));
    }

    public function test_a_protocol_is_only_added_to_a_submitted_intake_of_an_active_horse(): void
    {
        $intake = $this->source->intake()->sole();
        $url = "/admin/bookings/{$intake->id}/protocols";
        $before = Protocol::count();

        DB::table('intake_bookings')->where('id', $intake->id)->update(['submitted_at' => null, 'intake_status' => 'draft']);
        $this->post($url)->assertSessionHasErrors('protocol');
        DB::table('intake_bookings')->where('id', $intake->id)->update(['submitted_at' => now(), 'intake_status' => 'submitted', 'status' => 'cancelled']);
        $this->post($url)->assertSessionHasErrors('protocol');
        DB::table('intake_bookings')->where('id', $intake->id)->update(['status' => 'done']);
        DB::table('horses')->where('id', $this->source->horse_id)->update(['status' => 'archived']);
        $this->post($url)->assertSessionHasErrors('protocol');
        $this->assertSame($before, Protocol::count());

        auth('admin')->logout();
        $this->post($url)->assertRedirect('/admin/login');
        $this->assertSame($before, Protocol::count());
    }

    public function test_the_lookup_reports_the_intake_as_it_is_now(): void
    {
        $sourceOwner = $this->source->horse->owner;
        $url = "/admin/protocols/{$this->source->id}/copy-targets?to=same";
        $this->getJson($url)->assertOk()->assertJsonPath('intake', ['submitted' => true, 'cancelled' => false, 'user' => ['id' => $sourceOwner->id, 'name' => 'Bron Eigenaar']]);

        // The intake's user stays the previous owner after a transfer, and an unsubmitted intake is reported as such.
        DB::table('horses')->where('id', $this->source->horse_id)->update(['owner_id' => $this->owner->id]);
        DB::table('intake_bookings')->where('id', $this->source->intake->id)->update(['submitted_at' => null, 'completed_at' => null, 'intake_status' => 'draft']);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('intake', ['submitted' => false, 'cancelled' => false, 'user' => ['id' => $sourceOwner->id, 'name' => 'Bron Eigenaar']])
            ->assertJsonPath('horses.0.owner.id', $this->owner->id);
    }

    public function test_archived_therapist_is_not_carried_over(): void
    {
        Therapist::whereKey($this->source->therapist_id)->update(['archived_at' => now()]);

        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new'])
            ->assertSessionHasNoErrors();

        $this->assertNull(Protocol::where('horse_id', $this->target->id)->sole()->therapist_id);
    }

    public function test_rejects_targets_that_do_not_fit_the_selection(): void
    {
        $other = User::factory()->create();
        $foreign = Horse::create(['name' => 'Vreemd', 'owner_id' => $other->id, 'status' => 'active']);
        $archived = Horse::create(['name' => 'Archief', 'owner_id' => $this->owner->id, 'status' => 'archived']);
        $foreignDraft = IntakeBooking::create(['horse_id' => $foreign->id, 'user_id' => $other->id])->protocol;
        $cancelled = IntakeBooking::create(['horse_id' => $this->target->id, 'user_id' => $this->owner->id, 'status' => 'cancelled'])->protocol;
        $unconfigured = IntakeBooking::create(['horse_id' => $foreign->id, 'user_id' => $other->id])->protocol;
        $url = "/admin/protocols/{$this->source->id}/copy";
        $base = ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new'];
        $before = [Protocol::count(), IntakeBooking::count()];

        $this->post($url, ['horse_id' => $foreign->id] + $base)->assertSessionHasErrors('horse_id');
        $this->post($url, ['horse_id' => $archived->id] + $base)->assertSessionHasErrors('horse_id');
        $this->post($url, ['destination' => 'draft'] + $base)->assertSessionHasErrors('draft_id');
        $this->post($url, ['destination' => 'draft', 'draft_id' => $foreignDraft->id] + $base)->assertSessionHasErrors('draft_id');
        $this->post($url, ['destination' => 'draft', 'draft_id' => $cancelled->id] + $base)->assertSessionHasErrors('draft_id');
        $this->post($url, ['destination' => 'draft', 'draft_id' => (string) Str::uuid()] + $base)->assertSessionHasErrors('draft_id');
        $this->post($url, ['destination' => 'elsewhere'] + $base)->assertSessionHasErrors('destination');
        $this->post("/admin/protocols/{$unconfigured->id}/copy", $base)->assertSessionHasErrors('protocol');
        $this->assertSame($before, [Protocol::count(), IntakeBooking::count()]);

        // A draft that was configured in the meantime is no longer a valid destination.
        $draft = IntakeBooking::create(['horse_id' => $this->target->id, 'user_id' => $this->owner->id, 'submitted_at' => now()])->protocol;
        $this->post($url, ['destination' => 'draft', 'draft_id' => $draft->id] + $base)->assertSessionHasNoErrors();
        $this->post($url, ['destination' => 'draft', 'draft_id' => $draft->id] + $base)->assertSessionHasErrors('draft_id');
        $this->assertSame(1, AuditLog::where('action', 'copied')->count());
    }

    public function test_a_failure_leaves_no_partial_copy(): void
    {
        $this->mock(ProtocolTiming::class)->shouldReceive('synchronize')->andThrow(new RuntimeException('boom'));
        $before = [Protocol::count(), IntakeBooking::count(), DB::table('protocol_phases')->count(), DB::table('protocol_phase_supplements')->count(), AuditLog::count()];

        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new'])
            ->assertSessionHasErrors('copy');

        $this->assertSame($before, [Protocol::count(), IntakeBooking::count(), DB::table('protocol_phases')->count(), DB::table('protocol_phase_supplements')->count(), AuditLog::count()]);
    }

    public function test_the_targets_are_the_own_horse_or_the_active_horses_of_test_users(): void
    {
        $url = "/admin/protocols/{$this->source->id}/copy-targets";
        $this->getJson($url)->assertJsonValidationErrors('to');
        $this->getJson("{$url}?to=everyone")->assertJsonValidationErrors('to');

        // A real customer's horses are never listed; a second test user's are, ordered by user and then horse.
        Horse::create(['name' => 'Klantpaard', 'owner_id' => User::factory()->create()->id, 'status' => 'active']);
        Horse::create(['name' => 'Archief', 'owner_id' => $this->owner->id, 'status' => 'archived']);
        $tester = User::factory()->create(['name' => 'Anna Tester', 'is_test_user' => true]);
        $annasHorse = Horse::create(['name' => 'Zilver', 'owner_id' => $tester->id, 'status' => 'active']);
        $draft = IntakeBooking::create(['horse_id' => $this->target->id, 'user_id' => $this->owner->id, 'submitted_at' => now()])->protocol;
        $cancelledDraft = IntakeBooking::create(['horse_id' => $this->target->id, 'user_id' => $this->owner->id, 'status' => 'cancelled'])->protocol;
        // Without phases, but no longer unconfigured: the copier refuses it, so the dialog must not offer it.
        $analysed = IntakeBooking::create(['horse_id' => $this->target->id, 'user_id' => $this->owner->id])->protocol;
        $analysed->analysis()->create(['cause' => 'Al een analyse']);
        $active = IntakeBooking::create(['horse_id' => $annasHorse->id, 'user_id' => $tester->id, 'submitted_at' => now()])->protocol;
        $active->update(['status' => 'active']);

        $this->getJson("{$url}?to=test")->assertOk()
            ->assertJsonCount(2, 'horses')
            ->assertJsonPath('intake.submitted', true)
            ->assertJsonPath('horses.0.id', $annasHorse->id)
            ->assertJsonPath('horses.0.owner', ['id' => $tester->id, 'name' => 'Anna Tester', 'email' => $tester->email, 'is_test_user' => true])
            ->assertJsonPath('horses.0.has_active_protocol', true)
            ->assertJsonPath('horses.0.drafts', [])
            ->assertJsonPath('horses.1.id', $this->target->id)
            ->assertJsonPath('horses.1.status', 'active')
            ->assertJsonPath('horses.1.owner.id', $this->owner->id)
            ->assertJsonPath('horses.1.has_active_protocol', false)
            ->assertJsonPath('horses.1.drafts.0.id', $draft->id)
            ->assertJsonPath('horses.1.drafts.0.intake_submitted', true)
            ->assertJsonPath('horses.1.protocols', fn ($protocols) => collect($protocols)->pluck('id')->sort()->values()->all() === collect([$draft->id, $cancelledDraft->id, $analysed->id])->sort()->values()->all())
            ->assertJsonCount(1, 'horses.1.drafts');
        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'draft', 'draft_id' => $analysed->id])
            ->assertSessionHasErrors('draft_id');

        // The plain copy has one target: the protocol's own horse, also when it is archived (the dialog then says so).
        $this->getJson("{$url}?to=same")->assertOk()
            ->assertJsonCount(1, 'horses')
            ->assertJsonPath('horses.0.id', $this->source->horse_id)
            ->assertJsonPath('horses.0.owner.is_test_user', false)
            ->assertJsonPath('horses.0.protocols', [['id' => $this->source->id, 'title' => 'Herstelprotocol Bron']]);
        DB::table('horses')->where('id', $this->source->horse_id)->update(['status' => 'archived']);
        $this->getJson("{$url}?to=same")->assertOk()->assertJsonPath('horses.0.status', 'archived');
    }

    public function test_guests_cannot_use_the_copy_routes(): void
    {
        auth('admin')->logout();

        $this->get("/admin/protocols/{$this->source->id}/copy-targets?to=test")->assertRedirect('/admin/login');
        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new'])->assertRedirect('/admin/login');
    }

    /** @return array{0: IntakeAttachment, 1: IntakeBooking} */
    private function sourceIntakeWithPhoto(): array
    {
        Storage::fake('local');
        $intake = $this->source->intake;
        Storage::disk('local')->put("intake/{$intake->id}/huid.png", 'huid-bytes');
        $file = IntakeAttachment::create(['booking_id' => $intake->id, 'section_key' => 'fysiek', 'field_key' => 'foto-huid', 'name' => 'huid.png', 'path' => "intake/{$intake->id}/huid.png", 'mime' => 'image/png']);
        foreach ([
            ['paard', 'gewicht', 450],
            ['fysiek', 'foto-huid', ["attachment:{$file->id}:huid.png"]],
            ['__photos', "caption-{$file->id}", 'Plekje op de flank'],
            ['__photos', "slot-{$file->id}", 'flank'],
            ['contact', 'naam-eigenaar', 'Bron Eigenaar'],
        ] as [$section, $field, $value]) {
            $intake->answers()->create(['section_id' => $section, 'field_id' => $field, 'value' => json_encode($value)]);
        }

        return [$file, $intake];
    }

    private function draftWithOwnSettings(): Protocol
    {
        $draft = IntakeBooking::create(['horse_id' => $this->target->id, 'user_id' => $this->owner->id, 'submitted_at' => now()])->protocol;
        DB::table('protocols')->where('id', $draft->id)->update(['customer_settings' => json_encode([
            'weight_kg' => 480, 'feed_overrides' => [['id' => str_repeat('b', 64), 'status' => 'continue', 'note' => 'Doel-specifiek']],
        ])]);

        return $draft->fresh();
    }

    /** @return array<string, int> */
    private function counts(Protocol $protocol): array
    {
        $phaseIds = DB::table('protocol_phases')->where('protocol_id', $protocol->id)->pluck('id');
        $supplementIds = DB::table('protocol_phase_supplements')->whereIn('protocol_phase_id', $phaseIds)->pluck('id');
        $analysisIds = DB::table('protocol_analyses')->where('protocol_id', $protocol->id)->pluck('id');

        return [
            'phases' => $phaseIds->count(),
            'weeks' => DB::table('protocol_phase_weeks')->whereIn('protocol_phase_id', $phaseIds)->count(),
            'supplements' => $supplementIds->count(),
            'supplement_weeks' => DB::table('protocol_phase_supplement_weeks')->whereIn('protocol_phase_supplement_id', $supplementIds)->count(),
            'voeding' => DB::table('protocol_voeding_adviezen')->where('protocol_id', $protocol->id)->count(),
            'manual' => DB::table('protocol_manual_adviezen')->where('protocol_id', $protocol->id)->count(),
            'manual_phases' => DB::table('protocol_manual_advies_phase')->whereIn('protocol_phase_id', $phaseIds)->count(),
            'analysis' => $analysisIds->count(),
            'cards' => DB::table('protocol_advice')->whereIn('analysis_id', $analysisIds)->count(),
        ];
    }
}
