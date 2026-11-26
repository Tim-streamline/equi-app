<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\Horse;
use App\Models\PlusPage;
use App\Models\Protocol;
use App\Models\User;
use App\Support\ProtocolEvaluation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProtocolEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): Protocol
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'Europe/Amsterdam'));
        $owner = User::factory()->create();
        $horse = Horse::create(['owner_id' => $owner->id, 'name' => 'Moos', 'status' => 'active']);
        // Last day: December 2. Evaluation opens November 18; discount ends December 9.
        return $horse->protocols()->create(['title' => 'Plan', 'status' => 'active',
            'total_weeks' => 10, 'started_at' => '2026-09-24', 'published_at' => '2026-09-23 12:00:00']);
    }

    private function owner(string $id): void
    {
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($id) {
            public function __construct(private string $id) {}
            public function handle($request, $next) { $request->attributes->set('powersync_user_id', $this->id); return $next($request); }
        });
    }

    public function test_an_older_draft_remains_reachable_and_a_stale_save_cannot_replace_answers(): void
    {
        $plan = $this->plan();
        $this->owner($plan->horse->owner_id);
        $url = '/api/protocols/'.$plan->id.'/evaluation';
        $version = $this->getJson($url)->assertOk()->assertJsonPath('revision', 0)->json('questionVersion');
        $key = array_key_first(ProtocolEvaluation::QUESTIONS);
        $draft = ['question_version' => $version, 'revision' => 0, 'submit' => false, 'answers' => [$key => 'Eerste apparaat']];
        $this->postJson($url, $draft)->assertOk()->assertJsonPath('revision', 1);
        $this->postJson($url, [...$draft, 'answers' => [$key => 'Tweede apparaat']])->assertStatus(412);
        $this->getJson($url)->assertOk()->assertJsonPath('answers.'.$key, 'Eerste apparaat');
        $plan->update(['status' => 'completed']);
        $next = $plan->horse->protocols()->create(['title' => 'Vervolg', 'status' => 'active', 'published_at' => now()]);
        $home = '/api/horses/'.$plan->horse_id.'/dashboard';
        $this->getJson($home)->assertOk()->assertJsonPath('horseProtocols.0.id', $next->id)->assertJsonPath('evaluationDrafts.0.id', $plan->id);
        $this->postJson($url, [...$draft, 'revision' => 1, 'submit' => true,
            'answers' => array_fill_keys(array_keys(ProtocolEvaluation::QUESTIONS), 'Gereed')])->assertOk()->assertJsonPath('revision', 2);
        $this->getJson($home)->assertOk()->assertJsonCount(0, 'evaluationDrafts')
            ->assertJsonPath('submittedEvaluations.0.id', $plan->id)->assertJsonPath('submittedEvaluations.0.title', $plan->title);
        $this->getJson('/api/home')->assertOk()->assertJsonCount(0, 'submittedEvaluations');
        $otherHorse = Horse::create(['owner_id' => $plan->horse->owner_id, 'name' => 'Ander paard', 'status' => 'active']);
        $this->getJson('/api/horses/'.$otherHorse->id.'/dashboard')->assertOk()->assertJsonCount(0, 'submittedEvaluations');
        $this->getJson($url)->assertOk()->assertJsonPath('answers.'.$key, 'Gereed');
    }

    public function test_exact_evaluation_and_discount_boundaries_use_the_business_timezone(): void
    {
        $protocol = $this->plan();
        $service = app(ProtocolEvaluation::class);
        $this->assertFalse($service->timing($protocol, CarbonImmutable::parse('2026-11-17 23:59:59', 'Europe/Amsterdam'))['available']);
        $open = $service->timing($protocol, CarbonImmutable::parse('2026-11-18 00:00:00', 'Europe/Amsterdam'));
        $this->assertTrue($open['available']);
        $this->assertSame(14, $open['daysRemaining']);
        $this->assertSame('2026-12-02', $open['endsOn']);
        $this->assertTrue($service->timing($protocol, CarbonImmutable::parse('2026-12-09 23:59:59', 'Europe/Amsterdam'))['discountEligible']);
        $this->assertFalse($service->timing($protocol, CarbonImmutable::parse('2026-12-10 00:00:00', 'Europe/Amsterdam'))['discountEligible']);
        $protocol->started_at = null;
        $this->assertFalse($service->timing($protocol, CarbonImmutable::now())['available']);
    }

    public function test_owner_can_resume_and_submit_but_other_users_cannot_read_evaluations(): void
    {
        $protocol = $this->plan();
        $this->owner($protocol->horse->owner_id);
        $url = '/api/protocols/'.$protocol->id.'/evaluation';
        $version = app(ProtocolEvaluation::class)->payload($protocol, CarbonImmutable::now())['questionVersion'];
        $this->postJson($url, ['answers' => ['c12e1000-0000-4000-8000-000000000001' => 'Verbeterd'], 'submit' => false, 'question_version' => $version])->assertOk()->assertJsonPath('submittedAt', null);
        $this->assertNull(app(ProtocolEvaluation::class)->admin($protocol), 'Drafts are not submitted to the therapist');
        $this->postJson($url, ['answers' => ['c12e1000-0000-4000-8000-000000000001' => 'Verbeterd'], 'submit' => true, 'question_version' => $version])->assertUnprocessable();
        $answers = array_fill_keys(array_keys(ProtocolEvaluation::QUESTIONS), 'Mijn antwoord');
        $this->postJson($url, ['answers' => $answers, 'submit' => true, 'question_version' => $version])->assertOk();
        $this->assertNotNull(app(ProtocolEvaluation::class)->admin($protocol));
        $this->postJson($url, ['answers' => ['c12e1000-0000-4000-8000-000000000001' => 'Privéconcept'], 'submit' => false, 'question_version' => $version])->assertUnprocessable();
        $this->getJson($url)->assertOk()->assertJsonPath('answers.c12e1000-0000-4000-8000-000000000001', 'Mijn antwoord');
        $this->assertDatabaseCount('protocol_evaluations', 1);
        $this->owner(User::factory()->create()->id);
        $this->getJson($url)->assertNotFound();
        $this->postJson($url, ['answers' => $answers, 'submit' => true, 'question_version' => $version])->assertNotFound();
    }

    public function test_an_untouched_draft_can_be_saved_and_stays_an_empty_map(): void
    {
        $protocol = $this->plan();
        $this->owner($protocol->horse->owner_id);
        $url = '/api/protocols/'.$protocol->id.'/evaluation';
        $version = $this->getJson($url)->assertOk()->json('questionVersion');
        // The app sends "answers": {} until the owner has typed something.
        $saved = $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['answers' => new \stdClass, 'submit' => false, 'question_version' => $version]));
        $saved->assertOk();
        $this->assertStringContainsString('"answers":{}', $saved->getContent());
        $this->assertStringContainsString('"answers":{}', $this->getJson($url)->assertOk()->getContent());
        $this->postJson($url, ['answers' => [], 'submit' => true, 'question_version' => $version])->assertUnprocessable();
        $this->assertNull(app(ProtocolEvaluation::class)->admin($protocol));
    }

    public function test_a_form_opened_before_questions_were_added_or_removed_is_told_to_reopen(): void
    {
        $protocol = $this->plan();
        $this->owner($protocol->horse->owner_id);
        $url = '/api/protocols/'.$protocol->id.'/evaluation';
        $offer = $this->getJson($url)->assertOk()->json();
        $answers = array_fill_keys(array_keys($offer['questions']), 'Voorbeeldantwoord');
        // An added question the open form never showed must not surface as a missing answer.
        $added = \App\Models\ProtocolEvaluationQuestion::create(['label' => 'Nieuwe vraag', 'order' => 9, 'active' => true]);
        $this->postJson($url, ['answers' => $answers, 'submit' => true, 'question_version' => $offer['questionVersion']])->assertStatus(409);
        $added->update(['active' => false]);
        // A removed question the open form still answers must not surface as an unknown key.
        DB::table('protocol_evaluation_questions')->where('id', 'c12e1000-0000-4000-8000-000000000006')->update(['active' => false]);
        foreach ([true, false] as $submit) {
            $this->postJson($url, ['answers' => $answers, 'submit' => $submit, 'question_version' => $offer['questionVersion']])->assertStatus(409);
        }
        $this->assertDatabaseCount('protocol_evaluations', 0);
    }

    public function test_an_evaluation_stays_with_the_owner_who_wrote_it_after_the_horse_changes_owner(): void
    {
        $protocol = $this->plan();
        $first = $protocol->horse->owner_id;
        $this->owner($first);
        $url = '/api/protocols/'.$protocol->id.'/evaluation';
        $version = $this->getJson($url)->assertOk()->json('questionVersion');
        $this->postJson($url, ['answers' => ['c12e1000-0000-4000-8000-000000000001' => 'Privéconcept'], 'submit' => false, 'question_version' => $version])->assertOk();
        $second = User::factory()->create();
        $protocol->horse->update(['owner_id' => $second->id]);
        $this->owner($second->id);
        $service = app(ProtocolEvaluation::class);
        $this->assertFalse($service->timing($protocol->fresh(), CarbonImmutable::now())['available'], 'Home shows the new owner no evaluation notice');
        $this->assertStringNotContainsString('concept', $this->getJson($url)->assertUnprocessable()->getContent());
        $answers = array_fill_keys(array_keys(ProtocolEvaluation::QUESTIONS), 'Van de nieuwe eigenaar');
        $this->postJson($url, ['answers' => $answers, 'submit' => true, 'question_version' => $version])->assertUnprocessable();
        $this->assertDatabaseHas('protocol_evaluations', ['protocol_id' => $protocol->id, 'user_id' => $first, 'submitted_at' => null]);
        // Back with its author, the draft is there again.
        $protocol->horse->update(['owner_id' => $first]);
        $this->owner($first);
        $this->getJson($url)->assertOk()->assertJsonPath('answers.c12e1000-0000-4000-8000-000000000001', 'Privéconcept');
    }

    public function test_home_knows_a_sent_evaluation_and_a_plan_closed_before_its_scheduled_end(): void
    {
        $protocol = $this->plan();
        $this->owner($protocol->horse->owner_id);
        $service = app(ProtocolEvaluation::class);
        $timing = $service->timing($protocol, CarbonImmutable::now());
        $this->assertFalse($timing['submitted']);
        $this->assertFalse($timing['completed']);
        $url = '/api/protocols/'.$protocol->id.'/evaluation';
        $version = $this->getJson($url)->json('questionVersion');
        $this->postJson($url, ['answers' => ['c12e1000-0000-4000-8000-000000000001' => 'Concept'], 'submit' => false, 'question_version' => $version])->assertOk();
        $this->assertFalse($service->timing($protocol, CarbonImmutable::now())['submitted'], 'A draft is not sent');
        $this->postJson($url, ['answers' => array_fill_keys(array_keys(ProtocolEvaluation::QUESTIONS), 'Antwoord'), 'submit' => true, 'question_version' => $version])->assertOk();
        $this->assertTrue($service->timing($protocol, CarbonImmutable::now())['submitted']);
        // Closed on the opening day, fourteen days before the scheduled end.
        $protocol->update(['status' => 'completed']);
        $closed = $service->timing($protocol->fresh(), CarbonImmutable::now());
        $this->assertTrue($closed['completed']);
        $this->assertSame(14, $closed['daysRemaining']);
        $this->getJson($url)->assertOk()->assertJsonPath('completed', true);
    }

    public function test_admin_dates_name_their_timezone_so_the_panel_and_the_pdf_show_the_same_day(): void
    {
        $protocol = $this->plan();
        // 00:30 on 19 November in Amsterdam is still 18 November in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-11-19 00:30:00', 'Europe/Amsterdam'));
        $this->owner($protocol->horse->owner_id);
        $url = '/api/protocols/'.$protocol->id.'/evaluation';
        $version = $this->getJson($url)->json('questionVersion');
        $this->postJson($url, ['answers' => array_fill_keys(array_keys(ProtocolEvaluation::QUESTIONS), 'Antwoord'), 'submit' => true, 'question_version' => $version])->assertOk();
        $admin = app(ProtocolEvaluation::class)->admin($protocol);
        $this->assertSame('2026-11-18T23:30:00Z', $admin['submitted_at']);
        $this->assertSame('2026-11-18T23:30:00Z', $admin['updated_at']);
    }

    public function test_offer_uses_current_admin_price_and_never_creates_a_purchase_or_protocol(): void
    {
        $protocol = $this->plan();
        PlusPage::create(['id' => PlusPage::PAGE_ID, 'content' => PlusPage::defaults(), 'protocol_price_cents' => 14700]);
        $this->owner($protocol->horse->owner_id);
        $this->getJson('/api/protocols/'.$protocol->id.'/evaluation')->assertOk()->assertJsonPath('priceCents', 13230)->assertJsonPath('discountPercent', 10);
        $this->assertDatabaseCount('protocols', 1);
        $this->assertSame(0, DB::table('protocol_purchases')->count());
        $this->travelTo(CarbonImmutable::parse('2026-12-10 12:00:00', 'Europe/Amsterdam'));
        $this->getJson('/api/protocols/'.$protocol->id.'/evaluation')->assertOk()->assertJsonPath('priceCents', 14700)->assertJsonPath('discountPercent', 0);
    }

    public function test_question_edits_cannot_silently_change_the_meaning_of_an_existing_evaluation(): void
    {
        $protocol = $this->plan();
        $this->owner($protocol->horse->owner_id);
        $url = '/api/protocols/'.$protocol->id.'/evaluation';
        $offer = $this->getJson($url)->assertOk()->json();
        DB::table('protocol_evaluation_questions')->where('id', 'c12e1000-0000-4000-8000-000000000001')->update(['label' => 'Gewijzigde vraag']);
        $answers = array_fill_keys(array_keys($offer['questions']), 'Voorbeeldantwoord');
        $this->postJson($url, ['answers' => $answers, 'submit' => true, 'question_version' => $offer['questionVersion']])->assertStatus(409);
        $current = $this->getJson($url)->json();
        $this->postJson($url, ['answers' => $answers, 'submit' => false, 'question_version' => $current['questionVersion']])->assertOk();
        DB::table('protocol_evaluation_questions')->where('id', 'c12e1000-0000-4000-8000-000000000001')->update(['label' => 'Een latere vraag', 'active' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('questions.c12e1000-0000-4000-8000-000000000001', 'Gewijzigde vraag');
        $this->postJson($url, ['answers' => $answers, 'submit' => true, 'question_version' => $current['questionVersion']])->assertOk();
        $this->assertSame('Gewijzigde vraag', app(ProtocolEvaluation::class)->admin($protocol)['questions']['c12e1000-0000-4000-8000-000000000001']);
    }

    public function test_deactivating_a_stale_question_preserves_the_latest_edit(): void
    {
        $question = \App\Models\ProtocolEvaluationQuestion::firstOrFail();
        \App\Models\ProtocolEvaluationQuestion::whereKey($question->id)->update(['label' => 'Nieuwe tekst', 'order' => 23]);
        app(\App\Http\Controllers\Admin\ProtocolEvaluationController::class)->destroy($question);
        $this->assertDatabaseHas('protocol_evaluation_questions', ['id' => $question->id, 'label' => 'Nieuwe tekst', 'order' => 23, 'active' => false]);
    }

    public function test_admin_manages_questions_and_exports_only_the_evaluations_of_the_selected_horse(): void
    {
        $protocol = $this->plan();
        $this->owner($protocol->horse->owner_id);
        $offer = $this->getJson('/api/protocols/'.$protocol->id.'/evaluation')->json();
        $this->postJson('/api/protocols/'.$protocol->id.'/evaluation', ['submit' => true,
            'question_version' => $offer['questionVersion'], 'answers' => array_fill_keys(array_keys($offer['questions']), 'Mijn evaluatie')])->assertOk();
        $admin = \App\Models\AdminUser::create(['name' => 'Admin', 'email' => 'evaluation-admin@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $this->actingAs($admin, 'admin');
        $this->get('/admin/evaluation-questions')->assertOk();
        $seedId = array_key_first(ProtocolEvaluation::QUESTIONS);
        // Laravel trims request strings, so the stored label has no trailing space.
        $longLabel = trim(str_repeat('Een uitgebreide evaluatievraag. ', 20));
        $this->put('/admin/evaluation-questions/'.$seedId, ['label' => $longLabel, 'order' => 0, 'active' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['target_id' => $seedId, 'action' => 'updated']);
        $this->assertDatabaseHas('protocol_evaluation_questions', ['id' => $seedId, 'label' => $longLabel]);
        $this->post('/admin/evaluation-questions', ['label' => 'Nieuwe vraag', 'order' => 8])->assertSessionHasNoErrors();
        $question = \App\Models\ProtocolEvaluationQuestion::where('label', 'Nieuwe vraag')->sole();
        $this->put('/admin/evaluation-questions/'.$question->id, ['label' => 'Bijgewerkte vraag', 'order' => 2, 'active' => true])->assertSessionHasNoErrors();
        $this->delete('/admin/evaluation-questions/'.$question->id)->assertSessionHasNoErrors();
        $this->assertFalse($question->fresh()->active);
        $this->assertDatabaseCount('protocol_evaluations', 1);
        $this->get('/admin/horses/'.$protocol->horse_id)->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Horses/Show')->where('evaluations.0.answers.c12e1000-0000-4000-8000-000000000001', 'Mijn evaluatie'));
        $pdf = $this->get('/admin/horses/'.$protocol->horse_id.'/evaluations/'.$protocol->id.'.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        // There is no export of all evaluations: that address only exists for changing or removing a question.
        $this->get('/admin/evaluation-questions/export.pdf')->assertMethodNotAllowed();
        $otherHorse = Horse::create(['owner_id' => $protocol->horse->owner_id, 'name' => 'Ander paard', 'status' => 'active']);
        $this->get('/admin/horses/'.$otherHorse->id.'/evaluations/'.$protocol->id.'.pdf')->assertNotFound();
    }
}
