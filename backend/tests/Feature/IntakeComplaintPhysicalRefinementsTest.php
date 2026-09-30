<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\IntakeAnswersPdf;
use App\Support\IntakeReview;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IntakeComplaintPhysicalRefinementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_preserves_answers_attachments_and_customizations_with_correct_order(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $q = IntakeQuestionnaire::where('slug', 'protocol-intake')->firstOrFail();
        $patch = json_decode(file_get_contents(database_path('data/intake-complaint-physical-2026-09-30.json')), true);
        foreach ($patch['sections'] as $key => $changes) {
            $section = $q->sections()->where('key', $key)->firstOrFail();
            foreach ($changes['add'] as $addition) {
                $section->fields()->where('key', $addition['field']['id'])->delete();
            }
            foreach ($changes['retire'] as $id) {
                $section->fields()->create(['key' => $id, 'type' => 'text', 'label' => 'Oude vraag', 'order' => 500, 'active' => true]);
            }
        }
        $complaint = $q->sections()->where('key', 'klacht')->firstOrFail();
        $oldOrder = ['hulpvraag', 'begonnen-wanneer', 'subklacht', 'wens', 'acuut', 'acuut-toelichting', 'thema', 'da-behandeling', 'da-diagnose', 'onderzoek-focus', 'eerder-behandeld', 'eerder-wat', 'eerder-resultaat', 'huidige-aanpak', 'bloedonderzoek-gedaan', 'bloedonderzoek', 'foto-historie', 'gedragsveranderingen', 'allergie', 'stressfactoren'];
        foreach ($oldOrder as $order => $key) {
            $complaint->fields()->where('key', $key)->update(['order' => $order]);
        }
        $custom = $complaint->fields()->create(['key' => 'custom', 'label' => 'Eigen vraag', 'type' => 'text', 'order' => 600, 'active' => true]);
        $untouched = $complaint->fields()->where('key', 'allergie')->firstOrFail();
        $untouched->update(['label' => 'Eigen allergievraag']);
        $physical = $q->sections()->where('key', 'fysiek')->firstOrFail();
        $photo = $physical->fields()->where('key', 'foto-huid')->firstOrFail();
        $photo->update(['required' => true, 'optional' => false]);
        $booking = $this->booking();
        $attachment = IntakeAttachment::create(['booking_id' => $booking->id, 'section_key' => 'fysiek', 'field_key' => 'foto-huid', 'path' => 'existing.jpg', 'name' => 'existing.jpg', 'mime' => 'image/jpeg']);
        $this->answers($booking, ['klacht' => ['da-diagnose' => 'Bestaande diagnose', 'hulpvraag' => 'Bestaande klacht'], 'fysiek' => ['foto-huid' => ['attachment:'.$attachment->id.':existing.jpg']]]);
        $saved = $booking->answers()->get()->toJson();
        $migration = require database_path('migrations/2026_09_30_003000_refine_complaint_and_physical_intake.php');
        $migration->up();
        $snapshot = $q->sections()->with('fields')->get()->toJson();
        $this->travel(2)->seconds();
        $migration->up();
        $this->assertSame($snapshot, $q->sections()->with('fields')->get()->toJson());
        $this->assertSame($saved, $booking->answers()->get()->toJson());
        $this->assertSame('existing.jpg', $attachment->fresh()->path);
        $this->assertFalse($photo->fresh()->required);
        $this->assertTrue($photo->fresh()->optional);
        $this->assertTrue($custom->fresh()->active);
        $this->assertSame('Eigen allergievraag', $untouched->fresh()->label);
        foreach ($patch['sections'] as $key => $changes) {
            $section = $q->sections()->where('key', $key)->firstOrFail();
            foreach ($changes['retire'] as $id) {
                $this->assertFalse($section->fields()->where('key', $id)->firstOrFail()->active);
            }
            foreach ($changes['add'] as $addition) {
                $this->assertSame(1, $section->fields()->where('key', $addition['field']['id'])->count());
            }
        }
        $expected = json_decode(file_get_contents(database_path('data/intake-questionnaire.json')), true);
        foreach (['klacht', 'fysiek', 'gedrag'] as $id) {
            $definition = collect($expected['sections'])->firstWhere('id', $id);
            $section = $q->sections()->where('key', $id)->firstOrFail();
            $keys = $section->fields()->where('active', true)->where('key', '!=', 'custom')->orderBy('order')->pluck('key')->all();
            $this->assertSame(array_column($definition['fields'], 'id'), $keys, $id);
        }
    }

    public function test_review_and_pdf_follow_current_conditions_and_keep_hidden_answers(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $booking = $this->booking();
        $this->answers($booking, [
            'klacht' => ['hulpvraag' => 'Zichtbare hoofdklacht', 'ontstaan-verandering' => 'Weet ik niet', 'ontstaan-verandering-details' => 'HIDDEN-ONSET', 'klacht-frequentie' => 'Dagelijks', 'klacht-frequentie-anders' => 'HIDDEN-FREQUENCY', 'klacht-patronen' => 'Nee', 'klacht-patronen-details' => 'HIDDEN-PATTERN', 'klacht-onderzocht' => 'Nee', 'klacht-onderzoek-details' => 'HIDDEN-TREATMENT', 'klacht-gelijktijdig' => 'Weet ik niet', 'klacht-gelijktijdig-details' => 'HIDDEN-OTHER', 'da-diagnose' => 'HIDDEN-RETIRED'],
            'fysiek' => ['bespiering' => 'Normaal', 'spierverlies-locatie' => 'HIDDEN-MUSCLE'],
            'gedrag' => ['gedrag-signalen' => ['Weet ik niet'], 'gedrag-signalen-anders' => 'HIDDEN-BEHAVIOR'],
        ]);
        $review = app(IntakeReview::class)->forBooking($booking->fresh());
        $rows = collect($review['sections'])->flatMap(fn ($section) => $section['rows']);
        foreach (['ontstaan-verandering-details', 'klacht-frequentie-anders', 'klacht-patronen-details', 'klacht-onderzoek-details', 'klacht-gelijktijdig-details', 'da-diagnose', 'spierverlies-locatie', 'gedrag-signalen-anders'] as $key) {
            $this->assertFalse($rows->contains('key', $key), $key);
        }
        $this->assertFalse($rows->firstWhere('key', 'gedrag-signalen')['flagged']);
        $this->assertFalse($rows->firstWhere('key', 'foto-huid')['required']);
        $this->assertTrue($rows->firstWhere('key', 'foto-hoeven')['required']);
        $html = app(IntakeAnswersPdf::class)->html($booking->fresh());
        $this->assertStringNotContainsString('HIDDEN-', $html);
        $this->assertStringContainsString('Zichtbare hoofdklacht', $html);
        $this->answers($booking, ['fysiek' => ['bespiering' => 'Plaatselijk spierverlies zichtbaar'], 'klacht' => ['klacht-onderzocht' => 'Ja']]);
        $review = app(IntakeReview::class)->forBooking($booking->fresh());
        $rows = collect($review['sections'])->flatMap(fn ($section) => $section['rows']);
        $this->assertSame('HIDDEN-MUSCLE', $rows->firstWhere('key', 'spierverlies-locatie')['value']);
        $this->assertTrue($rows->firstWhere('key', 'spierverlies-locatie')['required']);
        $this->assertSame('HIDDEN-TREATMENT', $rows->firstWhere('key', 'klacht-onderzoek-details')['value']);
        $this->assertTrue($rows->firstWhere('key', 'klacht-onderzoek-details')['required']);
    }

    public function test_all_eight_hoof_photos_upload_and_remain_available_in_review(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        Storage::fake('local');
        $booking = $this->booking();
        $this->asApiUser($booking->user);
        $references = [];
        foreach (range(1, 8) as $i) {
            $references[] = $this->postJson('/api/intakes/'.$booking->id.'/attachments', [
                'horse_id' => $booking->horse_id, 'section' => 'fysiek', 'field' => 'foto-hoeven', 'file' => UploadedFile::fake()->image('hoef-'.$i.'.jpg'),
            ])->assertCreated()->json('value');
        }
        $this->answers($booking, ['fysiek' => ['foto-hoeven' => $references]]);
        $review = app(IntakeReview::class)->forBooking($booking->fresh());
        $rows = collect($review['sections'])->flatMap(fn ($section) => $section['rows']);
        $this->assertCount(8, $rows->firstWhere('key', 'foto-hoeven')['attachments']);
        foreach ($references as $ref) {
            $this->get('/api/intake-media/'.explode(':', $ref)[1])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        }
    }

    private function answers(IntakeBooking $booking, array $answers): void
    {
        foreach ($answers as $section => $fields) {
            foreach ($fields as $field => $value) {
                $booking->answers()->updateOrCreate(['section_id' => $section, 'field_id' => $field], ['value' => json_encode($value)]);
            }
        }
    }

    private function asApiUser(User $user): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $path = storage_path('framework/testing/intake-complaint-public.pem');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, openssl_pkey_get_details($key)['key']);
        config(['powersync.public_key_path' => $path, 'powersync.audience' => 'equinova']);
        $token = JWT::encode(['sub' => $user->id, 'aud' => 'equinova', 'iat' => time(), 'exp' => time() + 3600], $private, 'RS256');
        $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function booking(): IntakeBooking
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Intake regression']);

        return IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
    }
}
