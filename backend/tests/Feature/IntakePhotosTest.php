<?php

namespace Tests\Feature;

use App\Http\Controllers\IntakeActivityController;
use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\IntakeProgress;
use App\Support\IntakeReview;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntakePhotosTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): IntakeBooking
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);

        return IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
    }

    public function test_existing_uploads_and_metadata_remain_available_in_the_regular_section(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $booking = $this->booking();
        $photo = IntakeAttachment::create(['booking_id' => $booking->id, 'section_key' => 'klacht', 'field_key' => 'foto-historie', 'name' => 'old.jpg', 'path' => 'intake/old.jpg', 'mime' => 'image/jpeg']);
        foreach ([['klacht', 'foto-historie', ['attachment:'.$photo->id.':old.jpg']], ['__photos', 'year-'.$photo->id, (string) now()->year], ['__photos', 'planten-na', 'yes']] as [$section, $field, $value]) {
            $booking->answers()->create(['section_id' => $section, 'field_id' => $field, 'value' => json_encode($value)]);
        }
        $review = app(IntakeReview::class)->forBooking($booking);
        $this->assertSame('Foto’s voor je intake', $review['sections'][0]['title']);
        $row = collect($review['sections'][0]['rows'])->firstWhere('key', 'foto-historie');
        $this->assertSame($photo->id, $row['attachments'][0]['id']);
        $this->assertSame((string) now()->year, $row['attachments'][0]['year']);
        $this->assertSame('yes', $review['answers']['__photos']['planten-na']);
        $this->assertDatabaseHas('intake_attachments', ['id' => $photo->id, 'section_key' => 'klacht', 'path' => 'intake/old.jpg']);
    }

    public function test_deployment_moves_existing_and_custom_photos_without_rewriting_answers_or_admin_copy(): void
    {
        $q = IntakeQuestionnaire::create(['slug' => 'protocol-intake', 'name' => 'Intake', 'active' => true]);
        $old = $q->sections()->create(['key' => 'custom-section', 'title' => 'Custom', 'order' => 0, 'active' => true]);
        $field = $old->fields()->create(['key' => 'custom-photo', 'type' => 'photo', 'label' => 'Admin label', 'hint' => 'Admin instructions', 'required' => true, 'show_if' => ['choice' => 'Ja'], 'active' => true]);
        $text = $old->fields()->create(['key' => 'choice', 'type' => 'radio', 'label' => 'Choice', 'options' => ['Ja', 'Nee'], 'active' => true]);
        $booking = $this->booking();
        $file = IntakeAttachment::create(['booking_id' => $booking->id, 'section_key' => $old->key, 'field_key' => $field->key, 'name' => 'old.jpg', 'path' => 'intake/old.jpg', 'mime' => 'image/jpeg']);
        foreach (['choice' => 'Ja', 'custom-photo' => ['attachment:'.$file->id.':old.jpg']] as $key => $value) {
            $booking->answers()->create(['section_id' => $old->key, 'field_id' => $key, 'value' => json_encode($value)]);
        }
        $answers = $booking->answers()->get()->toJson();
        $migration = require database_path('migrations/2026_10_15_000004_move_intake_photos_into_section.php');
        $migration->up();
        $first = $q->sections()->first();
        $this->assertSame('intake-fotos', $first->key);
        $this->assertSame('Intake fotos', $first->title);
        $this->assertSame($first->id, $field->fresh()->section_id);
        $this->assertSame($old->key, $field->fresh()->answer_section_key);
        $this->assertSame($old->id, $text->fresh()->section_id);
        $this->assertSame(['custom-section.choice' => 'Ja'], $field->fresh()->show_if);
        $this->assertSame('Admin label', $field->fresh()->label);
        $this->assertSame('Admin instructions', $field->fresh()->hint);
        $snapshot = $q->sections()->with('fields')->get()->toJson();
        $migration->up();
        $this->assertSame($snapshot, $q->sections()->with('fields')->get()->toJson());
        $this->assertSame($answers, $booking->answers()->get()->toJson());
        $this->assertSame('ready', app(IntakeProgress::class)->forBooking($booking)['stage']);
        $this->assertSame($file->id, app(IntakeReview::class)->forBooking($booking)['sections'][0]['rows'][0]['attachments'][0]['id']);
        $booking->forceFill(['last_section' => $old->key, 'last_field' => $field->key])->save();
        $request = Request::create('/');
        $request->attributes->set('powersync_user_id', $booking->user_id);
        $resume = app(IntakeActivityController::class)->resume($request, $booking, app(IntakeProgress::class))->getData(true);
        $this->assertSame('intake-fotos', $resume['section']);
        $this->assertSame('custom-photo', $resume['field']);
    }

    public function test_admin_can_edit_the_photo_section_add_photo_questions_and_change_validation(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'photo-admin@example.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $section = IntakeQuestionnaire::first()->sections()->where('key', 'intake-fotos')->firstOrFail();
        $this->actingAs($admin, 'admin')->put('/admin/intake-questionnaire/sections/'.$section->id, [
            'questionnaire_id' => $section->questionnaire_id, 'key' => $section->key, 'title' => 'Aangepaste fotos',
            'intro' => 'Eigen uitleg', 'subtitle' => 'Foto’s', 'icon' => 'camera', 'minutes' => 5, 'order' => 0, 'active' => true,
        ])->assertSessionHasNoErrors();
        $data = ['section_id' => $section->id, 'key' => 'new-photo', 'label' => 'Nieuwe foto', 'type' => 'photo',
            'hint' => 'Eigen instructie', 'required' => true, 'optional' => false, 'tall' => false, 'order' => 0, 'active' => true];
        $this->post('/admin/intake-questionnaire/fields', $data)->assertSessionHasNoErrors();
        $field = IntakeField::where('key', 'new-photo')->firstOrFail();
        $booking = $this->booking();
        $progress = app(IntakeProgress::class)->forBooking($booking);
        $this->assertContains(['section' => 'intake-fotos', 'field' => 'new-photo'], $progress['missing']);
        $this->put('/admin/intake-questionnaire/fields/'.$field->id, array_replace($data, ['optional' => true, 'required' => false, 'label' => 'Optionele foto']))->assertSessionHasNoErrors();
        $this->assertNotContains(['section' => 'intake-fotos', 'field' => 'new-photo'], app(IntakeProgress::class)->forBooking($booking)['missing']);
        $this->assertSame('Aangepaste fotos', app(IntakeReview::class)->forBooking($booking)['sections'][0]['title']);
        $moved = IntakeField::where('key', 'foto-historie')->firstOrFail();
        $booking->answers()->create(['section_id' => 'klacht', 'field_id' => $moved->key, 'value' => '["attachment:old:file.jpg"]']);
        $this->put('/admin/intake-questionnaire/fields/'.$moved->id, array_replace($data, ['key' => 'renamed-photo']))->assertSessionHasErrors('key');
        $this->assertDatabaseHas('intake_answers', ['response_id' => $booking->id, 'section_id' => 'klacht', 'field_id' => 'foto-historie']);
        $originalSection = DB::table('intake_sections')->where('key', 'klacht')->value('id');
        $this->post('/admin/intake-questionnaire/fields', array_replace($data, ['section_id' => $originalSection, 'key' => 'foto-historie']))->assertSessionHasErrors('key');
    }
}
