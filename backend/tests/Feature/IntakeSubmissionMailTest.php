<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\IntakeAnswersPdf;
use App\Support\IntakeSubmissionMail;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class IntakeSubmissionMailTest extends TestCase
{
    use RefreshDatabase;

    private IntakeBooking $booking;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.from.address' => 'sender@example.test']);
        $user = User::factory()->create(['name' => 'Account Owner', 'email' => 'account@example.test']);
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Lúna / paard']);
        $this->booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id, 'intake_status' => 'draft', 'review_notes' => 'Private therapist notes']);
        $q = IntakeQuestionnaire::create(['slug' => 'protocol-intake', 'name' => 'Intake', 'active' => true]);
        $contact = $q->sections()->create(['key' => 'contact', 'title' => 'Contactgegevens', 'order' => 0, 'active' => true]);
        foreach (['email' => 'E-mailadres', 'naam-eigenaar' => 'Voor- en achternaam'] as $key => $label) {
            $contact->fields()->create(['key' => $key, 'label' => $label, 'type' => 'text', 'active' => true]);
        }
        $section = $q->sections()->create(['key' => 'paard', 'title' => 'Jouw paard', 'order' => 1, 'active' => true]);
        foreach ([
            ['key' => 'gewicht', 'label' => 'Gewicht', 'type' => 'number', 'unit' => 'kg'],
            ['key' => 'voer', 'label' => 'Voer', 'type' => 'repeater', 'repeater_sub' => [['id' => 'name', 'label' => 'Naam'], ['id' => 'amount', 'label' => 'Hoeveelheid']]],
            ['key' => 'klachten', 'label' => 'Klachten', 'type' => 'multi'],
            ['key' => 'foto', 'label' => 'Foto', 'type' => 'photo', 'optional' => true],
            ['key' => 'hidden', 'label' => 'Hidden question', 'type' => 'text', 'show_if' => ['gewicht' => '999']],
        ] as $field) {
            $section->fields()->create($field + ['active' => true]);
        }
        foreach (['contact' => ['email' => 'intake@example.test', 'naam-eigenaar' => 'Zoë Jansen'], 'paard' => ['gewicht' => 540, 'voer' => [['name' => 'Hooi', 'amount' => '12 kg']], 'klachten' => ['Jeuk', 'Hoesten'], 'hidden' => 'Hidden stale answer']] as $sid => $fields) {
            foreach ($fields as $fid => $value) {
                $this->booking->answers()->create(['section_id' => $sid, 'field_id' => $fid, 'value' => json_encode($value)]);
            }
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $path = storage_path('framework/testing/intake-mail-public.pem');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, openssl_pkey_get_details($key)['key']);
        config(['powersync.public_key_path' => $path, 'powersync.audience' => 'equinova']);
        $this->token = JWT::encode(['sub' => $user->id, 'aud' => 'equinova', 'iat' => time(), 'exp' => time() + 3600], $private, 'RS256');
    }

    private function upload(array $operations)
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/sync/upload', ['operations' => $operations]);
    }

    private function submission(array $data = []): array
    {
        return ['op' => 'PATCH', 'type' => 'intake_bookings', 'id' => $this->booking->id, 'data' => $data + ['intake_status' => 'submitted', 'submitted_at' => '2026-09-29T21:00:00Z']];
    }

    private function messages()
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages();
    }

    public function test_retiring_customer_copies_cancels_pending_delivery_and_preserves_answers(): void
    {
        $this->booking->forceFill(['answers_email_status' => 'pending', 'submission_email_status' => 'pending'])->save();
        $answers = $this->booking->answers()->count();
        $field = \App\Models\IntakeField::where('key', 'email')->firstOrFail();
        $field->update(['hint' => 'Na het afronden van de intake ontvang je op dit adres een kopie van je antwoorden.']);

        $migration = require database_path('migrations/2026_09_30_020000_retire_intake_customer_copies.php');
        $migration->up();
        $migration->up();
        $migration->down();

        $this->assertSame('cancelled', $this->booking->fresh()->answers_email_status);
        $this->assertSame('pending', $this->booking->fresh()->submission_email_status);
        $this->assertSame($answers, $this->booking->answers()->count());
        $this->assertNull($field->fresh()->hint);
        $this->assertArrayNotHasKey('intakes:send-answer-copies', \Illuminate\Support\Facades\Artisan::all());
        $this->assertArrayHasKey('intakes:send-submission-notifications', \Illuminate\Support\Facades\Artisan::all());
        $this->assertCount(0, $this->messages());
    }

    public function test_admin_download_reuses_answers_and_photos_without_private_notes_or_protocol(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->image('hoef.png', 100, 100);
        $path = Storage::disk('local')->putFile('intake', $file);
        $photo = IntakeAttachment::create(['booking_id' => $this->booking->id, 'section_key' => 'paard', 'field_key' => 'foto', 'name' => 'hoef.png', 'path' => $path, 'mime' => 'image/png']);
        $this->booking->answers()->create(['section_id' => 'paard', 'field_id' => 'foto', 'value' => json_encode(['attachment:'.$photo->id.':hoef.png'])]);
        $html = app(IntakeAnswersPdf::class)->html($this->booking);
        foreach (['540 kg', 'Hooi', '12 kg', 'Jeuk', 'Hoesten', 'hoef.png', 'data:image/png;base64,'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        foreach (['Private therapist notes', 'Hidden stale answer', 'Hidden question', '/admin/intake-media'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
        $this->get('/admin/bookings/'.$this->booking->id.'/answers.pdf')->assertRedirect('/admin/login');
        $this->actingAs(AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => 'owner', 'active' => true]), 'admin');
        $response = $this->get('/admin/bookings/'.$this->booking->id.'/answers.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->upload([$this->submission()])->assertOk();
        $this->assertNull($this->booking->fresh()->answers_email_status);
        $this->get('/admin/bookings/'.$this->booking->id)->assertInertia(fn (Assert $page) => $page->where('booking.submission_email_status', 'pending'));
    }

    public function test_pdf_download_uses_the_requested_filename_from_the_submission_and_intake_name(): void
    {
        $this->booking->answers()->create(['section_id' => 'paard', 'field_id' => 'naam', 'value' => json_encode('Rolo')]);
        $this->upload([$this->submission()])->assertOk();
        $this->actingAs(AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => 'owner', 'active' => true]), 'admin');
        $this->get('/admin/bookings/'.$this->booking->id.'/answers.pdf')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="EquiApp_protocolintake_29-09-2026_Rolo.pdf"');
    }

    public function test_first_submission_sets_review_status_and_normalizes_timestamp_without_resetting_review_on_replay(): void
    {
        $this->booking->update(['status' => 'confirmed']);
        $op = $this->submission(['submitted_at' => '2026-09-30T00:24:00+02:00']);
        $this->upload([$op])->assertOk();
        $booking = $this->booking->fresh();
        $this->assertSame('pending', $booking->status);
        $this->assertSame('Te beoordelen', $booking->status_label);
        $this->assertSame('2026-09-29 22:24:00', $booking->submitted_at->format('Y-m-d H:i:s'));
        $this->assertSame('30-09-2026 00:24', $booking->submitted_at_label);
        $booking->update(['status' => 'done']);
        $this->upload([$op])->assertOk();
        $this->assertSame('done', $booking->fresh()->status);
    }

    public function test_new_submission_without_a_timestamp_records_server_time(): void
    {
        $this->freezeTime();
        $op = $this->submission();
        unset($op['data']['submitted_at']);
        $this->upload([$op])->assertOk();
        $this->assertTrue($this->booking->fresh()->submitted_at->equalTo(now()->startOfSecond()));
    }

    public function test_therapist_notification_is_sent_once_after_commit_with_the_requested_content(): void
    {
        $this->booking->answers()->create(['section_id' => 'paard', 'field_id' => 'naam', 'value' => json_encode('Rolo')]);
        $op = $this->submission(['submitted_at' => '2026-09-30T00:24:00+02:00']);
        $this->upload([$op])->assertOk();
        $this->assertSame('pending', $this->booking->fresh()->submission_email_status);
        $this->assertCount(0, $this->messages());
        Event::listen(MessageSending::class, fn () => app(IntakeSubmissionMail::class)->send($this->booking->id));
        $this->artisan('intakes:send-submission-notifications')->assertSuccessful();
        $this->assertCount(1, $this->messages());
        $message = $this->messages()->first()->getOriginalMessage();
        $this->assertSame('contact@depaardentherapeut.nl', strtolower($message->getTo()[0]->getAddress()));
        $this->assertSame('Nieuwe protocolintake ingediend – Rolo', $message->getSubject());
        foreach (['Er is een nieuwe protocolintake ingediend.', 'Paard: Rolo', 'Gebruiker: Account Owner', 'Ingediend op: 30-09-2026 00:24', 'Bekijk intake in backend', route('admin.bookings.show', $this->booking)] as $text) {
            $this->assertStringContainsString($text, $message->getTextBody());
        }
        $this->assertStringContainsString('href="'.route('admin.bookings.show', $this->booking).'"', $message->getHtmlBody());
        $this->assertCount(0, $message->getAttachments());
        $this->assertSame('sent', $this->booking->fresh()->submission_email_status);
        $this->assertNotNull($this->booking->fresh()->submission_email_sent_at);
        $this->actingAs(AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => 'owner', 'active' => true]), 'admin');
        $this->get('/admin/bookings/'.$this->booking->id)->assertOk();
        $this->get('/admin/bookings/'.$this->booking->id.'/answers.pdf')->assertOk();
        $this->post('/admin/bookings/'.$this->booking->id.'/status', ['status' => 'done'])->assertRedirect();
        $replay = $op;
        $replay['data']['status'] = 'pending';
        $replay['data']['submitted_at'] = '2026-10-02T10:00:00Z';
        $replay['data']['notes'] = 'Later aangepast';
        $this->upload([$replay])->assertOk();
        $this->artisan('intakes:send-submission-notifications')->assertSuccessful();
        $this->assertCount(1, $this->messages());
        $this->assertSame('done', $this->booking->fresh()->status);
        $this->assertSame('30-09-2026 00:24', $this->booking->fresh()->submitted_at_label);
        $this->assertSame('Later aangepast', $this->booking->fresh()->notes);
    }

    public function test_notification_transport_failure_does_not_retry_uncertain_delivery(): void
    {
        $this->upload([$this->submission()])->assertOk();
        Event::listen(MessageSending::class, fn () => throw new RuntimeException('SMTP unavailable'));
        $this->artisan('intakes:send-submission-notifications')->assertFailed();
        $this->assertSame('failed', $this->booking->fresh()->submission_email_status);
        $this->assertSame('SMTP unavailable', $this->booking->fresh()->submission_email_error);
        $this->assertSame('submitted', $this->booking->fresh()->intake_status);
        $this->assertNull($this->booking->fresh()->answers_email_status);
        Event::forget(MessageSending::class);
        $this->upload([$this->submission()])->assertOk();
        $this->artisan('intakes:send-submission-notifications')->assertSuccessful();
        $this->assertCount(0, $this->messages());
    }

    public function test_notifications_exclude_drafts_rolled_back_batches_and_historical_submissions(): void
    {
        $this->upload([$this->submission(['intake_status' => 'draft', 'submitted_at' => null])])->assertOk();
        $this->assertNull($this->booking->fresh()->submission_email_status);
        $victim = IntakeBooking::create(['user_id' => User::factory()->create()->id]);
        $this->upload([$this->submission(), ['op' => 'PATCH', 'type' => 'intake_bookings', 'id' => $victim->id, 'data' => ['intake_status' => 'submitted']]])->assertForbidden();
        $this->assertNull($this->booking->fresh()->submission_email_status);
        $this->booking->update(['intake_status' => 'submitted', 'submitted_at' => now()]);
        $this->upload([$this->submission()])->assertOk();
        $this->assertNull($this->booking->fresh()->submission_email_status);
        $this->booking->update(['submitted_at' => null]);
        $this->upload([['op' => 'PATCH', 'type' => 'intake_bookings', 'id' => $this->booking->id, 'data' => ['notes' => 'Historical update']]])->assertOk();
        $this->assertNull($this->booking->fresh()->submitted_at);
        $this->assertNull($this->booking->fresh()->submission_email_status);
        $this->artisan('intakes:send-submission-notifications')->assertSuccessful();
        $this->assertCount(0, $this->messages());
    }

    public function test_notification_delivery_fields_cannot_be_forged_and_pending_delivery_waits_for_submission(): void
    {
        $this->upload([$this->submission(['submission_email_status' => 'sent', 'submission_email_sent_at' => now()->toISOString()])])->assertOk();
        $this->assertSame('pending', $this->booking->fresh()->submission_email_status);
        $this->assertNull($this->booking->fresh()->submission_email_sent_at);
        $this->upload([$this->submission(['intake_status' => 'draft', 'submitted_at' => null])])->assertOk();
        $this->artisan('intakes:send-submission-notifications')->assertSuccessful();
        $this->assertCount(0, $this->messages());
        $this->upload([$this->submission()])->assertOk();
        $this->artisan('intakes:send-submission-notifications')->assertSuccessful();
        $this->upload([$this->submission(['intake_status' => 'draft', 'submitted_at' => null])])->assertOk();
        $this->upload([$this->submission()])->assertOk();
        $this->artisan('intakes:send-submission-notifications')->assertSuccessful();
        $this->assertCount(1, $this->messages());
    }

    public function test_notification_waits_for_missing_smtp_credentials_without_claiming_delivery(): void
    {
        $this->upload([$this->submission()])->assertOk();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.transip.email', 'mail.mailers.smtp.username' => '', 'mail.mailers.smtp.password' => '']);
        $this->artisan('intakes:send-submission-notifications')->assertFailed();
        $this->assertSame('pending', $this->booking->fresh()->submission_email_status);
        $this->assertNull($this->booking->fresh()->submission_email_claimed_at);
        $this->assertNull($this->booking->fresh()->submission_email_error);
    }
}
