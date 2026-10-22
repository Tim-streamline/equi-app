<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeAttachment;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeRefinementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_updates_only_requested_attributes_and_preserves_answers_and_custom_fields(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $questionnaire = IntakeQuestionnaire::where('slug', 'protocol-intake')->firstOrFail();
        $patch = json_decode(file_get_contents(database_path('data/intake-refinements-2026-09-29.json')), true);
        foreach ($patch['sections'] as $key => $changes) {
            $section = $questionnaire->sections()->where('key', $key)->firstOrFail();
            foreach ($changes['add'] as $addition) {
                $section->fields()->where('key', $addition['field']['id'])->delete();
            }
            foreach ($changes['retire'] as $key) {
                $section->fields()->create(['key' => $key, 'label' => 'Oude vraag', 'type' => 'text', 'order' => 999, 'active' => true]);
            }
        }
        $horse = $questionnaire->sections()->where('key', 'paard')->firstOrFail();
        $horse->fields()->create(['key' => 'geboortedatum', 'label' => 'Geboortedatum', 'type' => 'date', 'hint' => 'Oude hulptekst', 'optional' => true, 'active' => true]);
        $custom = $horse->fields()->create(['key' => 'custom', 'label' => 'Eigen beheervraag', 'type' => 'text', 'order' => 1000, 'active' => true]);
        $unchanged = $horse->fields()->where('key', 'gewicht')->firstOrFail();
        $unchanged->update(['label' => 'Eigen gewichtstekst', 'hint' => 'Bewaren']);
        $user = User::factory()->create();
        $horseRecord = Horse::create(['owner_id' => $user->id, 'name' => 'Intake regression']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horseRecord->id]);
        $answer = $booking->answers()->create(['section_id' => 'paard', 'field_id' => 'eerste-eigenaar', 'value' => json_encode('ja')]);
        $migration = require database_path('migrations/2026_09_29_235000_refine_intake_questions.php');
        $migration->up();
        $first = $questionnaire->sections()->with('fields')->get()->toJson();
        $this->travel(2)->seconds();
        $migration->up();
        $this->assertSame($first, $questionnaire->sections()->with('fields')->get()->toJson());
        $this->assertSame(json_encode('ja'), $answer->fresh()->value);
        $this->assertSame('Eigen beheervraag', $custom->fresh()->label);
        $this->assertSame('Eigen gewichtstekst', $unchanged->fresh()->label);
        $this->assertSame('Bewaren', $unchanged->fresh()->hint);
        $this->assertFalse($horse->fields()->where('key', 'eerste-eigenaar')->firstOrFail()->active);
        $this->assertSame('Geboortedatum niet bekend? Vul hieronder dan de (geschatte) leeftijd in.', $horse->fields()->where('key', 'geboortedatum')->firstOrFail()->hint);
        $this->assertContains('geen van bovenstaande', $questionnaire->fresh()->none_options);
        $medical = $questionnaire->sections()->where('key', 'medisch')->firstOrFail();
        $fields = $medical->fields()->where('active', true)->orderBy('order')->get();
        $this->assertSame(['vacc-gepland' => 'Ja'], $fields->firstWhere('key', 'vacc-volgende')->show_if);
        $keys = $fields->pluck('key')->all();
        $index = array_search('ijzers', $keys);
        $this->assertSame(['ijzers', 'ijzers-afgelopen-twee-jaar', 'ijzers-af'], array_slice($keys, $index, 3));
        $this->assertFalse($medical->fields()->where('key', 'mestonderzoek-uitslag-file')->firstOrFail()->active);
        $this->assertSame('repeater', $fields->firstWhere('key', 'medicatie-ooit-details')->type);
        $this->assertSame('any-checked', $fields->firstWhere('key', 'medicatie-ooit-details')->show_if['medicatie-ooit-welke']);
    }

    public function test_fresh_install_matches_the_revised_questionnaire(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $questionnaire = IntakeQuestionnaire::where('slug', 'protocol-intake')->firstOrFail();
        $medical = $questionnaire->sections()->where('key', 'medisch')->firstOrFail();
        $this->assertSame(['Ja', 'Nee', 'Weet ik niet'], $medical->fields()->where('key', 'vacc-gepland')->firstOrFail()->options);
        $history = $questionnaire->sections()->where('key', 'geschiedenis')->firstOrFail();
        $event = $history->fields()->where('key', 'medische-gebeurtenissen')->firstOrFail();
        $this->assertSame('Wat was er aan de hand?', collect($event->repeater_sub)->firstWhere('id', 'symptomen')['label']);
        $this->assertSame('Hoe reageerde je paard daarop?', collect($event->repeater_sub)->firstWhere('id', 'reactie')['label']);
    }

    public function test_medical_events_not_applicable_counts_as_answer_and_hides_saved_events_in_review(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Intake regression']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $events = $booking->answers()->create(['section_id' => 'geschiedenis', 'field_id' => 'medische-gebeurtenissen',
            'value' => json_encode([])]);
        $booking->load('answers');
        $before = app(\App\Support\IntakeProgress::class)->forBooking($booking);
        $this->assertContains(['section' => 'geschiedenis', 'field' => 'medische-gebeurtenissen'], $before['missing']);
        $booking->answers()->create(['section_id' => 'geschiedenis', 'field_id' => 'na:medische-gebeurtenissen',
            'value' => json_encode('yes')]);

        $booking->unsetRelation('answers')->load('answers');
        $progress = app(\App\Support\IntakeProgress::class)->forBooking($booking);
        $this->assertNotContains(['section' => 'geschiedenis', 'field' => 'medische-gebeurtenissen'], $progress['missing']);
        $events->update(['value' => json_encode([['symptomen' => 'Eerdere blessure']])]);
        $booking->unsetRelation('answers')->load('answers');
        $review = app(\App\Support\IntakeReview::class)->forBooking($booking);
        $history = collect($review['sections'])->firstWhere('id', 'geschiedenis');
        $event = collect($history['rows'])->firstWhere('key', 'medische-gebeurtenissen');
        $this->assertTrue($event['not_applicable']);
        $this->assertFalse($event['empty']);
        $this->assertFalse($event['flagged']);
        $this->assertSame([], $event['protocol']);
    }

    public function test_insulin_resistance_migration_adds_conditional_questions_without_changing_answers(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $section = IntakeQuestionnaire::where('slug', 'protocol-intake')->firstOrFail()
            ->sections()->where('key', 'medisch')->firstOrFail();
        $section->fields()->whereIn('key', ['ir-onderzoek-wanneer-welk', 'ir-onderzoek-uitslag',
            'ir-uitslag-beschikbaar', 'ir-vermoeden-toelichting', 'ir-onderzoeken', 'ir-onderzoeken-anders',
            'ir-voeding-tussenkop', 'ir-voeding-vooraf', 'ir-voeding-vooraf-anders',
            'ir-hooi-hoeveelheid', 'ir-laatst-gegeten', 'ir-vasten-duur'])->delete();
        $section->fields()->where('key', 'ir-attest')->firstOrFail()->update([
            'label' => 'Onderzoeksresultaten urine-/bloedonderzoek',
            'show_if' => ['ir-status' => 'Vastgesteld door dierenarts/onderzoek'],
        ]);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'IR migration']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $answer = $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'ir-status',
            'value' => json_encode('Vastgesteld door dierenarts/onderzoek')]);

        $migration = require database_path('migrations/2026_10_21_000004_branch_insulin_resistance_questions.php');
        $migration->up();
        $keys = $section->fields()->where('active', true)->orderBy('order')->pluck('key')->all();
        $index = array_search('ir-status', $keys, true);
        $this->assertSame(['ir-status', 'ir-onderzoek-wanneer-welk', 'ir-onderzoek-uitslag',
            'ir-uitslag-beschikbaar', 'ir-attest', 'ir-vermoeden-toelichting'], array_slice($keys, $index, 6));
        $this->assertSame(['ir-status' => ['Ik vermoed dit', 'Niet vastgesteld, maar ik vermoed dit zelf']],
            $section->fields()->where('key', 'ir-vermoeden-toelichting')->firstOrFail()->show_if);
        $this->assertSame(['ir-status' => ['aangetoond door dierenarts', 'Vastgesteld door dierenarts/onderzoek',
            'Ja, vastgesteld door dierenarts/onderzoek'], 'ir-uitslag-beschikbaar' => 'Ja'],
            $section->fields()->where('key', 'ir-attest')->firstOrFail()->show_if);
        $migration->up();
        $this->assertSame($keys, $section->fields()->where('active', true)->orderBy('order')->pluck('key')->all());
        $this->assertSame(json_encode('Vastgesteld door dierenarts/onderzoek'), $answer->fresh()->value);
    }

    public function test_ems_migration_adds_both_branches_and_keeps_existing_answers(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $section = IntakeQuestionnaire::where('slug', 'protocol-intake')->firstOrFail()
            ->sections()->where('key', 'medisch')->firstOrFail();
        $newKeys = ['ems-diagnose-basis', 'ems-diagnose-basis-anders', 'ems-vermoeden-redenen', 'ems-vermoeden-anders'];
        $section->fields()->whereIn('key', [...$newKeys, 'ems-vermoeden-toelichting'])->delete();
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'EMS migration']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $answer = $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'ems-status',
            'value' => json_encode('Ik vermoed dit')]);

        $migration = require database_path('migrations/2026_10_21_000005_branch_ems_questions.php');
        $migration->up();
        $keys = $section->fields()->where('active', true)->orderBy('order')->pluck('key')->all();
        $index = array_search('ems-status', $keys, true);
        $this->assertSame(array_merge(['ems-status'], $newKeys), array_slice($keys, $index, 5));
        $this->assertSame(['ems-status' => ['Ik vermoed dit', 'Niet vastgesteld, maar ik vermoed dit zelf'],
            'ems-vermoeden-redenen' => 'Anders, namelijk'],
            $section->fields()->where('key', 'ems-vermoeden-anders')->firstOrFail()->show_if);
        $migration->up();
        $this->assertSame($keys, $section->fields()->where('active', true)->orderBy('order')->pluck('key')->all());
        $this->assertSame(json_encode('Ik vermoed dit'), $answer->fresh()->value);
    }

    public function test_kpu_migration_adds_followups_and_keeps_existing_results(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $section = IntakeQuestionnaire::where('slug', 'protocol-intake')->firstOrFail()
            ->sections()->where('key', 'medisch')->firstOrFail();
        $section->fields()->whereIn('key', ['kpu-onderzoek-datum', 'kpu-onderzoek-uitslag', 'kpu-vermoeden-toelichting', 'kpu-uitslag-beschikbaar'])->delete();
        $section->fields()->where('key', 'kpu-attest')->firstOrFail()->update([
            'label' => 'Onderzoeksresultaten urine-/bloedonderzoek',
            'show_if' => ['kpu-status' => 'Aangetoond met urineonderzoek'],
        ]);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'KPU migration']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $answer = $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'kpu-status',
            'value' => json_encode('Aangetoond met urineonderzoek')]);

        $migration = require database_path('migrations/2026_10_21_000006_branch_kpu_questions.php');
        $migration->up();
        $keys = $section->fields()->where('active', true)->orderBy('order')->pluck('key')->all();
        $index = array_search('kpu-status', $keys, true);
        $this->assertSame(['kpu-status', 'kpu-onderzoek-datum', 'kpu-onderzoek-uitslag', 'kpu-attest',
            'kpu-vermoeden-toelichting'], array_slice($keys, $index, 5));
        $date = $section->fields()->where('key', 'kpu-onderzoek-datum')->firstOrFail();
        $this->assertSame('date', $date->type);
        $this->assertSame('dd-mm-jjjj of bijvoorbeeld voorjaar 2025', $date->placeholder);
        $this->assertSame(['kpu-status' => ['Ik vermoed dit', 'Niet vastgesteld, maar ik vermoed dit zelf']],
            $section->fields()->where('key', 'kpu-vermoeden-toelichting')->firstOrFail()->show_if);
        $migration->up();
        $this->assertSame($keys, $section->fields()->where('active', true)->orderBy('order')->pluck('key')->all());
        $this->assertSame(json_encode('Aangetoond met urineonderzoek'), $answer->fresh()->value);
    }

    public function test_ir_research_migration_preserves_answers_and_requires_a_received_result_file(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $questionnaire = IntakeQuestionnaire::where('slug', 'protocol-intake')->firstOrFail();
        $medical = $questionnaire->sections()->where('key', 'medisch')->firstOrFail();
        $keys = ['ir-onderzoeken', 'ir-onderzoeken-anders', 'ir-voeding-tussenkop', 'ir-voeding-vooraf',
            'ir-voeding-vooraf-anders', 'ir-hooi-hoeveelheid', 'ir-laatst-gegeten', 'ir-vasten-duur'];
        $medical->fields()->whereIn('key', $keys)->delete();
        $medical->fields()->where('key', 'ir-onderzoek-wanneer-welk')->firstOrFail()->update(['type' => 'text']);
        $medical->fields()->where('key', 'ir-attest')->firstOrFail()->update(['optional' => true]);

        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'IR research']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'ir-status',
            'value' => json_encode('Ja, vastgesteld door dierenarts/onderzoek')]);
        $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'ir-uitslag-beschikbaar', 'value' => json_encode('Ja')]);
        $reference = 'attachment:00000000-0000-4000-8000-000000000001:uitslag.pdf';
        $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'ir-attest', 'value' => json_encode([$reference])]);

        $migration = require database_path('migrations/2026_10_21_000007_configure_ir_research_and_optional_answers.php');
        $migration->up();
        $this->assertSame('month_year', $medical->fields()->where('key', 'ir-onderzoek-wanneer-welk')->firstOrFail()->type);
        $this->assertFalse($medical->fields()->where('key', 'ir-attest')->firstOrFail()->optional);
        $this->assertSame('multi', $medical->fields()->where('key', 'ir-onderzoeken')->firstOrFail()->type);
        $this->assertContains('weet ik niet', $questionnaire->fresh()->none_options);
        $this->assertSame(['Niet van toepassing'], $questionnaire->sections()->where('key', 'geschiedenis')->firstOrFail()
            ->fields()->where('key', 'medische-gebeurtenissen')->firstOrFail()->options);

        $booking->load('answers');
        $progress = app(\App\Support\IntakeProgress::class)->forBooking($booking);
        $this->assertContains(['section' => 'medisch', 'field' => 'ir-attest'], $progress['missing']);
        $attachment = new IntakeAttachment(['booking_id' => $booking->id,
            'section_key' => 'medisch', 'field_key' => 'ir-attest', 'name' => 'uitslag.pdf',
            'path' => 'intake/test/uitslag.pdf', 'mime' => 'application/pdf']);
        $attachment->id = '00000000-0000-4000-8000-000000000001';
        $attachment->save();
        $progress = app(\App\Support\IntakeProgress::class)->forBooking($booking);
        $this->assertNotContains(['section' => 'medisch', 'field' => 'ir-attest'], $progress['missing']);
        $migration->up();
        $this->assertSame(count($keys), $medical->fields()->whereIn('key', $keys)->count());
    }
}
