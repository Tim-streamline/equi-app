<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\IntakePhotoRules;
use App\Support\IntakeProgress;
use App\Support\IntakeReview;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfiguredIntakePhotosTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): IntakeBooking
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);

        return IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
    }

    public function test_migration_updates_only_known_photo_questions_without_rewriting_saved_data(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $section = IntakeQuestionnaire::first()->sections()->where('key', 'intake-fotos')->firstOrFail();
        $field = $section->fields()->where('key', 'foto-historie')->firstOrFail();
        $field->update(['label' => 'Old label', 'photo_config' => null]);
        $custom = $section->fields()->create(['key' => 'custom', 'label' => 'Admin custom question', 'type' => 'photo', 'optional' => true, 'active' => true]);
        $booking = $this->booking();
        $attachment = IntakeAttachment::create(['booking_id' => $booking->id, 'section_key' => 'klacht', 'field_key' => $field->key, 'name' => 'old.jpg', 'path' => 'intake/old.jpg', 'mime' => 'image/jpeg']);
        $booking->answers()->create(['section_id' => 'klacht', 'field_id' => $field->key, 'value' => json_encode(['attachment:'.$attachment->id.':old.jpg'])]);
        $booking->answers()->create(['section_id' => '__photos', 'field_id' => 'year-'.$attachment->id, 'value' => '"2023"']);
        $before = $booking->answers()->get()->toJson();
        $others = IntakeQuestionnaire::first()->sections()->where('key', '!=', 'intake-fotos')->with('fields')->get()->toJson();
        $migration = require database_path('migrations/2026_10_16_000001_configure_intake_photo_questions.php');
        $migration->up();
        $this->assertSame('Je paard door de jaren heen', $field->fresh()->label);
        $this->assertSame(['max' => 10, 'year_required' => true], $field->fresh()->photo_config);
        $this->assertSame('klacht', $field->fresh()->answer_section_key);
        $this->assertSame('Admin custom question', $custom->fresh()->label);
        $this->assertSame($before, $booking->answers()->get()->toJson());
        $this->assertSame($others, IntakeQuestionnaire::first()->sections()->where('key', '!=', 'intake-fotos')->with('fields')->get()->toJson());
        $this->assertSame('intake/old.jpg', $attachment->fresh()->path);
        $review = app(IntakeReview::class)->forBooking($booking);
        $photo = collect($review['sections'][0]['rows'])->firstWhere('key', $field->key)['attachments'][0];
        $this->assertSame($attachment->id, $photo['id']);
        $this->assertSame('2023', $photo['year']);
        $migration->up();
        $this->assertSame($before, $booking->answers()->get()->toJson());
    }

    public function test_progress_and_review_respect_years_slots_and_not_applicable(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $booking = $this->booking();
        $history = IntakeField::where('key', 'foto-historie')->firstOrFail();
        $hooves = IntakeField::where('key', 'foto-hoeven')->firstOrFail();
        $rules = app(IntakePhotoRules::class);
        $refs = ['attachment:one:a.jpg'];
        $this->assertFalse($rules->answered($history, $refs, []));
        $this->assertTrue($rules->answered($history, $refs, ['__photos' => ['year-one' => '2023']]));
        $this->assertFalse($rules->answered($history, $refs, ['__photos' => ['year-one' => '9999']]));
        $refs = [];
        $meta = [];
        foreach ($hooves->photo_config['slots'] as $slot) {
            $refs[] = 'attachment:'.$slot['id'].':hoof.jpg';
            $meta['slot-'.$slot['id']] = $slot['id'];
        }
        $this->assertFalse($rules->answered($hooves, $refs, []));
        $this->assertTrue($rules->answered($hooves, $refs, ['__photos' => $meta]));
        $booking->answers()->create(['section_id' => '__photos', 'field_id' => 'weide-na', 'value' => '"yes"']);
        $booking->answers()->create(['section_id' => 'klacht', 'field_id' => 'foto-historie', 'value' => '["attachment:one:a.jpg"]']);
        $this->assertContains(['section' => 'intake-fotos', 'field' => 'foto-historie'], app(IntakeProgress::class)->forBooking($booking->fresh())['missing']);
        $booking->answers()->create(['section_id' => '__photos', 'field_id' => 'year-one', 'value' => '"2023"']);
        $this->assertNotContains(['section' => 'intake-fotos', 'field' => 'foto-historie'], app(IntakeProgress::class)->forBooking($booking->fresh())['missing']);
        $rows = app(IntakeReview::class)->forBooking($booking->fresh())['sections'][0]['rows'];
        $row = collect($rows)->firstWhere('key', 'foto-weide');
        $this->assertTrue($row['not_applicable']);
        $this->assertFalse($row['empty']);
        $html = view('intake.answers-pdf', ['booking' => $booking, 'sections' => [['nr' => 0, 'title' => 'Intake fotos', 'rows' => [$row]]], 'images' => []])->render();
        $this->assertStringContainsString('Niet van toepassing', $html);
        $this->assertStringNotContainsString('Niet ingevuld', $html);
    }

    public function test_admin_can_edit_and_validate_photo_configuration(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'photos@example.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $field = IntakeField::where('key', 'foto-hoeven')->firstOrFail();
        $data = ['section_id' => $field->section_id, 'key' => $field->key, 'label' => 'Own title', 'type' => 'photo', 'required' => false, 'optional' => true, 'tall' => false, 'order' => 4, 'active' => true];
        $url = '/admin/intake-questionnaire/fields/'.$field->id;
        $config = ['max' => 2, 'allow_na' => true, 'slots' => [['id' => 'own-view', 'label' => 'Own view']]];
        $this->actingAs($admin, 'admin')->put($url, $data + ['photo_config_json' => json_encode($config)])->assertSessionHasNoErrors();
        $this->assertSame($config, $field->fresh()->photo_config);
        $this->put($url, $data)->assertSessionHasNoErrors();
        $this->assertSame($config, $field->fresh()->photo_config, 'An older admin form cannot erase photo settings');
        foreach (['{"slots":[{"id":"x","label":"X"},{"id":"x","label":"Y"}]}', '{"max":0}', '{"example_url":"javascript:alert(1)"}', '{"year_required":"bad"}', '"bad"'] as $invalid) {
            $this->put($url, $data + ['photo_config_json' => $invalid])->assertSessionHasErrors('photo_config_json');
        }
    }
}
