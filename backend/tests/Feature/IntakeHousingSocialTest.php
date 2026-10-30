<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\User;
use App\Support\IntakeAnswersPdf;
use App\Support\IntakeConditions;
use App\Support\IntakeProgress;
use App\Support\IntakeReview;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IntakeHousingSocialTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): IntakeBooking
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Housing tests']);
        return IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
    }

    private function answer(IntakeBooking $booking, string $section, string $key, mixed $value): void
    {
        $booking->answers()->updateOrCreate(['section_id' => $section, 'field_id' => $key], ['value' => json_encode($value)]);
        $booking->unsetRelation('answers');
    }

    public function test_pasture_followups_ignore_old_answers_when_the_horse_no_longer_has_pasture_access(): void
    {
        $booking = $this->booking();
        $this->answer($booking, 'huisvesting', 'komt-op-weide', 'Nee, nooit');
        $this->answer($booking, 'huisvesting', 'weide-omschrijving', 'Anders, namelijk');
        $this->answer($booking, 'huisvesting', 'weide-bodem', ['Anders, namelijk']);
        $this->answer($booking, 'huisvesting', 'weide-schuil', 'Ja, altijd');
        $followups = ['weide-omschrijving-anders', 'weide-bodem-anders', 'weide-schuil-soort'];
        $visible = collect(app(IntakeReview::class)->forBooking($booking)['sections'])
            ->flatMap(fn ($section) => $section['rows'])->pluck('key')->all();
        $missing = array_column(app(IntakeProgress::class)->forBooking($booking->load('answers'))['missing'], 'field');
        foreach ($followups as $key) {
            $this->assertNotContains($key, $visible);
            $this->assertNotContains($key, $missing);
        }

        $this->answer($booking, 'huisvesting', 'komt-op-weide', 'Ja, het hele jaar door');
        $visible = collect(app(IntakeReview::class)->forBooking($booking)['sections'])
            ->flatMap(fn ($section) => $section['rows'])->pluck('key')->all();
        foreach ($followups as $key) {
            $this->assertContains($key, $visible);
        }
    }

    public function test_pasture_condition_upgrade_is_repeatable_and_preserves_admin_customizations(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $field = IntakeField::where('key', 'weide-bodem-anders')->sole();
        $field->update(['show_if' => ['weide-bodem' => 'Anders, namelijk']]);
        $custom = IntakeField::where('key', 'weide-omschrijving-anders')->sole();
        $custom->update(['show_if' => ['komt-op-weide' => 'Ja, seizoensgebonden']]);
        $migration = require database_path('migrations/2026_10_30_000002_guard_pasture_followup_questions.php');
        $migration->up();
        $expected = $field->fresh()->show_if;
        $this->assertArrayHasKey('komt-op-weide', $expected);
        $migration->up();
        $this->assertSame($expected, $field->fresh()->show_if);
        $this->assertSame(['komt-op-weide' => 'Ja, seizoensgebonden'], $custom->fresh()->show_if);
    }

    public function test_cross_section_followups_and_hidden_answers_use_the_same_rules_for_progress_review_and_pdf(): void
    {
        config(['intake.require_grouped_conditions' => true]);
        $booking = $this->booking();
        $this->answer($booking, 'huisvesting', 'leefruimte-paarden', 3);
        $this->answer($booking, 'huisvesting', 'ruwvoer-ongestoord', 'Regelmatig niet');
        $review = app(IntakeReview::class);
        $ids = fn () => collect($review->forBooking($booking)['sections'])->flatMap(fn ($s) => $s['rows'])->pluck('key')->all();
        $this->assertContains('wegjagen-frequentie', $ids());
        $missing = app(IntakeProgress::class)->forBooking($booking->load('answers'))['missing'];
        $this->assertContains('wegjagen-frequentie', array_column($missing, 'field'));
        $this->answer($booking, 'sociaal', 'wegjagen-frequentie', 'Meerdere keren per dag');
        $this->answer($booking, 'sociaal', 'wegjagen-reactie', ['Anders, namelijk...']);
        $this->answer($booking, 'sociaal', 'wegjagen-reactie-anders', 'Wacht achter het hek.');
        $this->assertContains('wegjagen-reactie-anders', $ids());
        $html = app(IntakeAnswersPdf::class)->html($booking);
        $this->assertStringContainsString('Wacht achter het hek.', $html);
        $this->assertStringContainsString('Meerdere keren per dag', $html);
        $this->answer($booking, 'huisvesting', 'ruwvoer-ongestoord', 'Ja, altijd');
        $this->assertNotContains('wegjagen-frequentie', $ids());
        $this->assertNotContains('wegjagen-reactie-anders', $ids());
        $result = $review->forBooking($booking);
        $historical = collect($result['historical_sections'])->flatMap(fn ($s) => $s['rows']);
        $this->assertSame('Wacht achter het hek.', $historical->firstWhere('key', 'wegjagen-reactie-anders')['value']);
        $this->assertFalse($historical->firstWhere('key', 'wegjagen-frequentie')['flagged']);
        $this->assertSame('"Wacht achter het hek."', $booking->answers()->where('field_id', 'wegjagen-reactie-anders')->value('value'));
        $this->assertStringContainsString('Geen actuele bevinding', app(IntakeAnswersPdf::class)->html($booking));
        $this->answer($booking, 'sociaal', 'sociaal-spanning', ['Wegjagen bij voer']);
        $this->assertContains('wegjagen-frequentie', $ids());
        $this->answer($booking, 'sociaal', 'sociaal-leven', ['Individueel zonder zicht/neuscontact met andere paarden']);
        $this->assertNotContains('wegjagen-frequentie', $ids());
    }

    public function test_grouped_questions_do_not_block_submission_from_older_apps_until_enabled(): void
    {
        $booking = $this->booking();
        $this->answer($booking, 'huisvesting', 'leefruimte-paarden', 3);
        $this->answer($booking, 'huisvesting', 'ruwvoer-ongestoord', 'Regelmatig niet');
        $ids = collect(app(IntakeReview::class)->forBooking($booking)['sections'])->flatMap(fn ($s) => $s['rows'])->pluck('key')->all();
        $this->assertContains('wegjagen-frequentie', $ids, 'the question is still shown and reviewed');
        $missing = fn () => array_column(app(IntakeProgress::class)->forBooking($booking->load('answers'))['missing'], 'field');
        $this->assertNotContains('wegjagen-frequentie', $missing());
        $this->assertNotContains('ruwvoer-ongestoord', $missing());
        $this->assertTrue(app(IntakeConditions::class)->grouped(IntakeField::where('key', 'wegjagen-frequentie')->sole()->show_if));
        $this->assertFalse(app(IntakeConditions::class)->grouped(['huisvesting.leefruimte-paarden' => 'Ja']));
        config(['intake.require_grouped_conditions' => true]);
        $this->assertContains('wegjagen-frequentie', $missing());
    }

        public function test_optional_map_and_archived_answers_remain_accessible_in_review_and_export(): void
    {
        Storage::fake('local');
        $booking = $this->booking();
        $image = UploadedFile::fake()->image('plattegrond.png', 1200, 900);
        $path = $image->store('intake-tests', 'local');
        $file = IntakeAttachment::create(['booking_id' => $booking->id, 'section_key' => 'huisvesting', 'field_key' => 'leefomgeving-overzicht', 'name' => 'plattegrond.png', 'mime' => 'image/png', 'path' => $path]);
        $this->answer($booking, 'huisvesting', 'leefomgeving-overzicht', ['attachment:'.$file->id.':plattegrond.png']);
        $this->answer($booking, 'huisvesting', 'huisvesting-toelichting', 'Bewaarde historische toelichting.');
        IntakeField::where('key', 'huisvesting-toelichting')->update(['active' => false]);
        $review = app(IntakeReview::class)->forBooking($booking);
        $map = collect($review['sections'])->flatMap(fn ($s) => $s['rows'])->firstWhere('key', 'leefomgeving-overzicht');
        $this->assertFalse($map['required']);
        $this->assertTrue($map['large_attachments']);
        $this->assertSame($file->id, $map['attachments'][0]['id']);
        $html = app(IntakeAnswersPdf::class)->html($booking);
        $this->assertStringContainsString('photo overview', $html);
        $this->assertStringContainsString('plattegrond.png', $html);
        $this->assertStringContainsString('Bewaarde historische toelichting.', $html);
        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    public function test_an_old_wet_lying_answer_still_shows_the_shelter_question(): void
    {
        $booking = $this->booking();
        $field = IntakeField::where('key', 'schuilplaats-inrichting')->sole();
        // Simulate a database where the shelter condition was created before the legacy answer was listed.
        $old = $field->show_if;
        $old['$any'][1]['huisvesting.buiten-droog-liggen'] = array_values(array_diff($old['$any'][1]['huisvesting.buiten-droog-liggen'], ['Nee / vaak nat, modderig of oncomfortabel']));
        $field->update(['show_if' => $old]);
        $this->answer($booking, 'huisvesting', 'schuilmogelijkheden', ['Geen']);
        $this->answer($booking, 'huisvesting', 'buiten-droog-liggen', 'Nee / vaak nat, modderig of oncomfortabel');
        $ids = fn () => collect(app(IntakeReview::class)->forBooking($booking)['sections'])->flatMap(fn ($s) => $s['rows'])->pluck('key')->all();
        $this->assertNotContains('schuilplaats-inrichting', $ids());

        $migration = require database_path('migrations/2026_10_27_000010_match_legacy_answer_in_shelter_condition.php');
        $migration->up();
        $migration->up();

        $this->assertContains('schuilplaats-inrichting', $ids());
        $this->assertSame(1, collect($field->fresh()->show_if['$any'][1]['huisvesting.buiten-droog-liggen'])->filter(fn ($v) => $v === 'Nee / vaak nat, modderig of oncomfortabel')->count());
    }

    public function test_targeted_migration_keeps_old_answers_and_does_not_duplicate_questions(): void
    {
        $booking = $this->booking();
        $this->answer($booking, 'huisvesting', 'buiten-droog-liggen', 'Alleen in bepaalde delen van het jaar');
        $before = $booking->answers()->first()->fresh()->getAttributes();
        $patch = json_decode(file_get_contents(database_path('data/housing-social-2026-10-27.json')), true);
        foreach ($patch['sections'] as $section) foreach ($section['fields'] as $change) {
            if (! $change['existing']) IntakeField::where('key', $change['definition']['id'])->delete();
        }
        $migration = require database_path('migrations/2026_10_27_000002_expand_housing_and_social_intake.php');
        $migration->up();
        $count = IntakeField::count();
        $map = IntakeField::where('key', 'leefomgeving-overzicht')->sole();
        $map->update(['label' => 'Eigen plattegrondvraag']);
        $migration->up();
        $this->assertSame($count, IntakeField::count());
        $this->assertSame('Eigen plattegrondvraag', $map->fresh()->label);
        $this->assertSame($before, $booking->answers()->first()->getAttributes());
        $this->assertContains('Alleen in bepaalde delen van het jaar', IntakeField::where('key', 'buiten-droog-liggen')->sole()->options);
    }

    public function test_moving_a_followup_preserves_visibility_for_legacy_parent_answers(): void
    {
        $booking = $this->booking();
        $field = IntakeField::where('key', 'ontworming-laatst')->sole();
        $destination = IntakeField::where('key', 'leefruimte-paarden')->sole()->section_id;
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'move-alias@example.test', 'password' => 'password', 'role' => 'owner', 'active' => true]);
        $visible = fn () => collect(app(IntakeReview::class)->forBooking($booking)['sections'])
            ->flatMap(fn ($section) => $section['rows'])->contains('key', $field->key);
        $answers = ['Combinatie van beide' => true, 'Een combinatie van beide' => true, 'Mijn paard wordt niet ontwormd' => false];
        foreach ($answers as $answer => $expected) {
            $this->answer($booking, 'medisch', 'ontworming-methode', $answer);
            $this->assertSame($expected, $visible(), $answer.' before move');
        }
        $this->answer($booking, 'medisch', 'ontworming-methode', 'Combinatie van beide');
        $this->actingAs($admin, 'admin')->put('/admin/intake-questionnaire/fields/'.$field->id, [
            'section_id' => $destination, 'key' => $field->key, 'label' => $field->label,
            'type' => $field->type, 'order' => $field->order, 'required' => true,
            'optional' => false, 'tall' => false, 'active' => true, 'show_if_json' => json_encode($field->show_if),
        ])->assertSessionHasNoErrors();
        $this->assertSame('medisch', $field->fresh()->answer_section_key);
        $this->assertContains('Combinatie van beide', $field->fresh()->show_if['medisch.ontworming-methode']);
        $this->assertSame('"Combinatie van beide"', $booking->answers()->where('field_id', 'ontworming-methode')->value('value'));
        foreach ($answers as $answer => $expected) {
            $this->answer($booking, 'medisch', 'ontworming-methode', $answer);
            $this->assertSame($expected, $visible(), $answer.' after move');
        }
    }

    public function test_admin_can_edit_nested_visibility_and_invalid_conditions_are_rejected(): void
    {
        $this->booking();
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'housing-admin@example.test', 'password' => 'password', 'role' => 'owner', 'active' => true]);
        $field = IntakeField::where('key', 'wegjagen-frequentie')->sole();
        $rule = ['$all' => [['huisvesting.leefruimte-paarden' => ['gte' => 2]], ['$any' => [['sociaal.sociaal-spanning' => 'Wegjagen bij voer'], ['huisvesting.ruwvoer-ongestoord' => 'Zelden']]]]];
        $this->assertTrue(app(IntakeConditions::class)->valid($rule));
        $payload = ['section_id' => $field->section_id, 'key' => $field->key, 'label' => 'Mijn eigen vraag', 'type' => 'radio', 'order' => $field->order, 'required' => true, 'optional' => false, 'tall' => false, 'active' => true, 'options_json' => json_encode($field->options), 'show_if_json' => json_encode($rule)];
        $url = '/admin/intake-questionnaire/fields/'.$field->id;
        $this->actingAs($admin, 'admin')->put($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame($rule, $field->fresh()->show_if);
        $payload['show_if_json'] = '{"$any":["invalid"]}';
        $this->put($url, $payload)->assertSessionHasErrors('show_if_json');
        $this->assertSame($rule, $field->fresh()->show_if);
        $booking = IntakeBooking::firstOrFail();
        $this->answer($booking, 'sociaal', 'wegjagen-frequentie', 'Dagelijks');
        $payload['section_id'] = IntakeField::where('key', 'leefruimte-paarden')->sole()->section_id;
        $payload['show_if_json'] = '{"$any":[{"sociaal-spanning":"Wegjagen bij voer"},{"huisvesting.ruwvoer-ongestoord":"Zelden"}]}';
        $this->put($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame('sociaal', $field->fresh()->answer_section_key);
        $this->assertSame(['$any' => [['sociaal.sociaal-spanning' => 'Wegjagen bij voer'], ['huisvesting.ruwvoer-ongestoord' => 'Zelden']]], $field->fresh()->show_if);
        $this->assertSame('"Dagelijks"', $booking->answers()->where('field_id', $field->key)->value('value'));
    }
}
