<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
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
        $horse->fields()->where('key', 'geboortedatum')->update(['hint' => 'Oude hulptekst']);
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
}
