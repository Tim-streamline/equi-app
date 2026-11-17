<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\DoseBuildup;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\Protocol;
use App\Models\ProtocolAdvice;
use App\Models\ProtocolPhaseSupplement;
use App\Models\ProtocolTemplate;
use App\Models\Supplement;
use App\Models\Therapist;
use App\Models\User;
use App\Models\VoedingAdvies;
use App\Support\ProtocolDoseBuildup;
use App\Support\ProtocolTiming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $sourceIntake = IntakeBooking::create(['horse_id' => $sourceHorse->id, 'user_id' => $sourceOwner->id, 'submitted_at' => now(), 'status' => 'pending']);

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

        $this->owner = User::factory()->create(['name' => 'Doel Eigenaar', 'email' => 'doel@example.com']);
        $this->target = Horse::create(['name' => 'Doel', 'owner_id' => $this->owner->id, 'weight_kg' => 300, 'status' => 'active']);
    }

    public function test_copies_the_structure_to_a_new_draft_with_its_own_intake(): void
    {
        $sourceCounts = $this->counts($this->source);
        $before = Protocol::count();

        $response = $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new']);

        $copy = Protocol::where('horse_id', $this->target->id)->sole();
        $response->assertRedirect("/admin/protocols/{$copy->id}")->assertSessionHas('success', fn ($message) => str_contains($message, 'Doel (Doel Eigenaar)') && ! str_contains($message, 'Let op'));
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

        $intake = $copy->intake()->sole();
        $this->assertSame($this->owner->id, $intake->user_id);
        $this->assertNull($intake->submitted_at);
        $this->assertSame(0, $intake->answers()->count());

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
        $this->post($url, ['user_id' => $this->source->horse->owner_id, 'horse_id' => $this->source->horse_id] + $base)->assertSessionHasErrors('horse_id');
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

    public function test_lookup_endpoints_find_users_and_their_horses_with_reusable_drafts(): void
    {
        $this->getJson('/admin/protocols/copy-targets/users?q=d')->assertOk()->assertExactJson(['users' => []]);
        $this->getJson('/admin/protocols/copy-targets/users?q=%25%25')->assertOk()->assertExactJson(['users' => []]);
        $this->getJson('/admin/protocols/copy-targets/users?q=DOEL@')->assertOk()
            ->assertJsonPath('users.0.id', $this->owner->id)
            ->assertJsonPath('users.0.active_horses_count', 1)
            ->assertJsonCount(1, 'users');

        Horse::create(['name' => 'Archief', 'owner_id' => $this->owner->id, 'status' => 'archived']);
        $draft = IntakeBooking::create(['horse_id' => $this->target->id, 'user_id' => $this->owner->id, 'submitted_at' => now()])->protocol;
        IntakeBooking::create(['horse_id' => $this->target->id, 'user_id' => $this->owner->id, 'status' => 'cancelled']);

        $this->getJson("/admin/protocols/copy-targets/users/{$this->owner->id}/horses")->assertOk()
            ->assertJsonPath('horses.0.name', 'Archief')
            ->assertJsonPath('horses.0.status', 'archived')
            ->assertJsonPath('horses.1.id', $this->target->id)
            ->assertJsonPath('horses.1.has_active_protocol', false)
            ->assertJsonPath('horses.1.drafts.0.id', $draft->id)
            ->assertJsonPath('horses.1.drafts.0.intake_submitted', true)
            ->assertJsonCount(1, 'horses.1.drafts');
    }

    public function test_guests_cannot_use_the_copy_routes(): void
    {
        auth('admin')->logout();

        $this->get('/admin/protocols/copy-targets/users?q=doel')->assertRedirect('/admin/login');
        $this->get("/admin/protocols/copy-targets/users/{$this->owner->id}/horses")->assertRedirect('/admin/login');
        $this->post("/admin/protocols/{$this->source->id}/copy", ['user_id' => $this->owner->id, 'horse_id' => $this->target->id, 'destination' => 'new'])->assertRedirect('/admin/login');
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
