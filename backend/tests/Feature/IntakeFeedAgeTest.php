<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\IntakeAge;
use App\Support\IntakeProgress;
use App\Support\IntakeReview;
use App\Support\ProtocolNutrition;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeFeedAgeTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): IntakeBooking
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Feed test']);

        return IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
    }

    public function test_migration_merges_balancer_once_preserving_answers_and_nutrition_overrides(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $section = IntakeQuestionnaire::first()->sections()->where('key', 'voer')->firstOrFail();
        $custom = $section->fields()->create(['key' => 'custom', 'label' => 'Own question', 'type' => 'text', 'active' => true]);
        foreach (['balancer', 'balancer-nu'] as $key) {
            $section->fields()->create(['key' => $key, 'label' => $key, 'type' => 'text', 'active' => true]);
        }
        $booking = $this->booking();
        $before = ['bijvoeding-nu' => 'Ja', 'huidige-bijvoeding' => [['merk' => 'A', 'product' => 'Feed', 'hoeveelheid' => '1 kg']],
            'balancer-nu' => 'Ja', 'balancer' => [['merk' => 'B', 'product' => 'Minerals', 'hoeveelheid' => '100 g']]];
        foreach ($before as $key => $value) {
            $booking->answers()->create(['section_id' => 'voer', 'field_id' => $key, 'value' => json_encode($value)]);
        }
        $oldFeeds = app(ProtocolNutrition::class)->currentFeeds($before);
        $original = $booking->answers()->where('field_id', 'balancer')->firstOrFail();
        $migration = require database_path('migrations/2026_10_16_000002_update_intake_feed_and_age_questions.php');
        $migration->up();
        $answers = $booking->answers()->get()->mapWithKeys(fn ($a) => [$a->field_id => json_decode($a->value, true)])->all();
        $this->assertCount(2, $answers['huidige-bijvoeding']);
        $this->assertSame($oldFeeds, app(ProtocolNutrition::class)->currentFeeds($answers));
        $this->assertSame($original->value, $original->fresh()->value);
        $this->assertSame($before['huidige-bijvoeding'], $answers['__bag_feed_migration']['feed']);
        $this->assertFalse($section->fields()->where('key', 'balancer')->firstOrFail()->active);
        $this->assertSame('Own question', $custom->fresh()->label);
        $saved = $booking->answers()->get()->toJson();
        $migration->up();
        $this->assertSame($saved, $booking->answers()->get()->toJson());
        $answers['huidige-bijvoeding'] = []; // A user can remove the migrated product without it reappearing.
        $this->assertSame([], app(ProtocolNutrition::class)->currentFeeds($answers));
    }

    public function test_gate_only_and_negative_balancer_answers_migrate_without_reviving_stale_feed(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $migration = require database_path('migrations/2026_10_16_000002_update_intake_feed_and_age_questions.php');
        foreach (['Ja', 'Nee'] as $gate) {
            $booking = $this->booking();
            foreach (['balancer-nu' => $gate, 'bijvoeding-nu' => 'Nee', 'huidige-bijvoeding' => [['product' => 'Stale feed']]] as $key => $value) {
                $booking->answers()->create(['section_id' => 'voer', 'field_id' => $key, 'value' => json_encode($value)]);
            }
            $migration->up();
            $answers = $booking->answers()->get()->mapWithKeys(fn ($a) => [$a->field_id => json_decode($a->value, true)])->all();
            $this->assertSame($gate, $answers['bijvoeding-nu']);
            $this->assertSame([['product' => 'Stale feed']], $answers['__bag_feed_migration']['feed']);
            $this->assertSame([], app(ProtocolNutrition::class)->currentFeeds($answers));
            if ($gate === 'Ja') {
                $this->assertSame([], $answers['huidige-bijvoeding']);
            }
        }
    }

    public function test_age_progress_and_review_allow_estimate_or_date_and_reject_invalid_dates(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        IntakeField::where('key', '!=', 'leeftijd')->update(['active' => false]);
        $booking = $this->booking();
        $age = $booking->answers()->create(['section_id' => 'paard', 'field_id' => 'leeftijd', 'value' => '0']);
        $this->assertSame('ready', app(IntakeProgress::class)->forBooking($booking->fresh())['stage']);
        $this->travelTo(now()->setDate(2026, 10, 16));
        $date = $booking->answers()->create(['section_id' => 'paard', 'field_id' => 'geboortedatum', 'value' => '"2016-10-17"']);
        $age->update(['value' => 'null']);
        $this->assertSame('ready', app(IntakeProgress::class)->forBooking($booking->fresh())['stage']);
        $review = app(IntakeReview::class)->forBooking($booking->fresh());
        $row = collect(collect($review['sections'])->firstWhere('id', 'paard')['rows'])->firstWhere('key', 'leeftijd');
        $this->assertSame("9 jaar\nGeboortedatum: 2016-10-17", $row['value']);
        $html = view('intake.answers-pdf', ['booking' => $booking, 'sections' => $review['sections'], 'images' => []])->render();
        $this->assertStringContainsString('Geboortedatum: 2016-10-17', $html);
        $date->update(['value' => '"31-02-2020"']);
        $age->update(['value' => '12']);
        $this->assertSame('continue', app(IntakeProgress::class)->forBooking($booking->fresh())['stage']);
        $rules = app(IntakeAge::class);
        foreach (['2023-02-29', '2027-01-01', '2020-13-10', ''] as $invalid) {
            $this->assertNull($rules->fromDate($invalid));
        }
        $this->assertSame(2, $rules->fromDate('29-02-2024'));
        $this->assertSame(0, $rules->fromDate('2026-10-16'));
        $this->assertFalse($rules->answered(-1, null));
        $this->assertFalse($rules->answered(1.5, null));
    }

    public function test_admin_can_change_age_copy_and_configuration_without_losing_answer_keys(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'age@example.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $field = IntakeField::where('key', 'leeftijd')->firstOrFail();
        $data = ['section_id' => $field->section_id, 'key' => $field->key, 'label' => 'Own age title', 'type' => 'number', 'required' => true, 'optional' => false, 'tall' => false, 'order' => 4, 'active' => true];
        $url = '/admin/intake-questionnaire/fields/'.$field->id;
        $config = $field->age_config;
        $config['toggle_label'] = 'Own date action';
        $this->actingAs($admin, 'admin')->put($url, $data + ['age_config_json' => json_encode($config)])->assertSessionHasNoErrors();
        $this->assertSame($config, $field->fresh()->age_config);
        $this->put($url, $data)->assertSessionHasNoErrors();
        $this->assertSame($config, $field->fresh()->age_config);
        $withoutHint = $config;
        unset($withoutHint['calculated_hint']);
        $this->put($url, $data + ['age_config_json' => json_encode($withoutHint)])->assertSessionHasNoErrors();
        $this->assertSame($withoutHint, $field->fresh()->age_config);
        $this->put($url, $data + ['age_config_json' => json_encode($withoutHint + ['calculated_hint' => ''])])->assertSessionHasNoErrors();
        foreach (['"bad"', '{}', json_encode(array_replace($config, ['date_field' => 'leeftijd'])), json_encode(array_replace($config, ['date_field' => 'other']))] as $invalid) {
            $this->put($url, $data + ['age_config_json' => $invalid])->assertSessionHasErrors('age_config_json');
        }
    }
}
