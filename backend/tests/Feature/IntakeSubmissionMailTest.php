<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\IntakeAnswersPdf;
use App\Support\IntakeSubmissionMail;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class IntakeSubmissionMailTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\HasSubscription;

    private IntakeBooking $booking;

    private string $token;

    public function test_existing_intake_attachments_need_ownership_but_not_plus(): void
    {
        Storage::fake('local');
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/intakes/'.$this->booking->id.'/attachments', [
                'horse_id' => $this->booking->horse_id, 'section' => 'paard', 'field' => 'foto',
                'file' => UploadedFile::fake()->image('horse.jpg'),
            ])->assertCreated();
        $this->assertStringStartsWith('attachment:', $response->json('value'));
        $this->assertDatabaseCount('intake_attachments', 1);
        $this->assertDatabaseCount('plus_intake_entitlements', 0);
        $this->booking->horse->update(['owner_id' => User::factory()->create()->id]);
        $this->postJson('/api/intakes/'.$this->booking->id.'/attachments', [
            'horse_id' => $this->booking->horse_id, 'section' => 'paard', 'field' => 'foto',
            'file' => UploadedFile::fake()->image('denied.jpg'),
        ])->assertForbidden();
        $this->assertDatabaseCount('intake_attachments', 1);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.from.address' => 'sender@example.test']);
        $user = User::factory()->create(['name' => 'Account Owner', 'email' => 'account@example.test']);
        $this->subscribe($user);
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
            ['key' => 'klachten', 'label' => 'Klachten', 'type' => 'multi', 'options' => ['Jeuk', 'Hoesten']],
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
        $field = IntakeField::where('key', 'email')->firstOrFail();
        $field->update(['hint' => 'Na het afronden van de intake ontvang je op dit adres een kopie van je antwoorden.']);

        $migration = require database_path('migrations/2026_09_30_020000_retire_intake_customer_copies.php');
        $migration->up();
        $migration->up();
        $migration->down();

        $this->assertSame('cancelled', $this->booking->fresh()->answers_email_status);
        $this->assertSame('pending', $this->booking->fresh()->submission_email_status);
        $this->assertSame($answers, $this->booking->answers()->count());
        $this->assertNull($field->fresh()->hint);
        $this->assertArrayNotHasKey('intakes:send-answer-copies', Artisan::all());
        $this->assertArrayHasKey('intakes:send-submission-notifications', Artisan::all());
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
        foreach (['540 kg', 'Hooi', '12 kg', 'Jeuk', 'Hoesten', 'hoef.png', 'file:///'] as $text) {
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

    public function test_pdf_marks_the_same_attention_points_and_critical_answers_as_the_admin(): void
    {
        IntakeField::where('key', 'klachten')->firstOrFail()->update(['flag_if' => ['Jeuk'], 'options' => ['Jeuk', 'Hoesten', 'Niet geselecteerde optie']]);
        IntakeField::where('key', 'gewicht')->firstOrFail()->update(['critical_if' => ['540']]);

        $html = app(IntakeAnswersPdf::class)->html($this->booking->fresh());
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $attention = $xpath->query('//div[@class="review-answer attention"]');
        $critical = $xpath->query('//div[@class="review-answer critical"]');

        $this->assertCount(1, $attention);
        $this->assertStringContainsString('Klachten', $attention->item(0)->textContent);
        $this->assertStringContainsString('Aandachtspunt', $attention->item(0)->textContent);
        $this->assertCount(1, $critical);
        $this->assertStringContainsString('540 kg', $critical->item(0)->textContent);
        $this->assertStringContainsString('Blokkeert protocol', $critical->item(0)->textContent);
        $this->assertSame('Jeuk', $xpath->query('//li[@class="flagged-option"]')->item(0)->textContent);
        $this->assertSame('Hoesten', $xpath->query('//li[not(@class)]')->item(0)->textContent);
        $this->assertStringNotContainsString('Niet geselecteerde optie', $html);

        // Changing the admin rule also changes the export; there is no separate PDF flag logic.
        IntakeField::where('key', 'klachten')->firstOrFail()->update(['flag_if' => null]);
        $this->assertStringNotContainsString('class="review-answer attention"', app(IntakeAnswersPdf::class)->html($this->booking->fresh()));
    }

    public function test_pdf_includes_answers_from_all_thirteen_sections_and_formats_structured_values(): void
    {
        $questionnaire = IntakeQuestionnaire::firstOrFail();
        foreach (range(3, 13) as $number) {
            $key = 'controle-'.$number;
            $section = $questionnaire->sections()->create(['key' => $key, 'title' => 'Sectie '.$number, 'order' => $number - 1, 'active' => true]);
            foreach ([
                ['key' => 'tekst', 'type' => 'textarea', 'value' => "Uniek antwoord sectie $number\nTweede regel $number"],
                ['key' => 'keuze', 'type' => 'radio', 'options' => ['Ja', 'Nee'], 'value' => 'Ja'],
                ['key' => 'meerdere', 'type' => 'multi', 'options' => ['Geselecteerd A', 'Geselecteerd B', 'Niet gekozen C'], 'value' => ['Geselecteerd A', 'Geselecteerd B']],
                ['key' => 'aantal', 'type' => 'number', 'unit' => 'kg', 'value' => 0],
                ['key' => 'datum', 'type' => 'month_year', 'value' => ['version' => 1, 'precision' => 'month', 'year' => 2025, 'month' => 4]],
                ['key' => 'onbekend', 'type' => 'month_year', 'value' => ['version' => 1, 'precision' => 'unknown']],
                ['key' => 'herhaald', 'type' => 'repeater', 'repeater_sub' => [['id' => 'naam', 'label' => 'Naam'], ['id' => 'sinds', 'label' => 'Sinds', 'type' => 'month_year']],
                    'value' => [['naam' => 'Eerste invoer '.$number, 'sinds' => ['version' => 1, 'precision' => 'year', 'year' => 2024]],
                        ['naam' => 'Tweede invoer '.$number, 'sinds' => ['version' => 1, 'precision' => 'range', 'start' => ['version' => 1, 'precision' => 'month', 'year' => 2025, 'month' => 1], 'ongoing' => true]]]],
                ['key' => 'niet-van-toepassing', 'type' => 'repeater', 'options' => ['Niet van toepassing'], 'value' => []],
            ] as $config) {
                $value = $config['value'];
                unset($config['value']);
                $section->fields()->create($config + ['label' => $config['key'], 'active' => true]);
                $this->booking->answers()->create(['section_id' => $key, 'field_id' => $config['key'], 'value' => json_encode($value)]);
            }
            $this->booking->answers()->create(['section_id' => $key, 'field_id' => 'na:niet-van-toepassing', 'value' => json_encode('yes')]);
        }
        $html = app(IntakeAnswersPdf::class)->html($this->booking->fresh());
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $this->assertCount(13, $xpath->query('//h2'));
        $this->assertCount(11, $xpath->query('//li[text()="Geselecteerd A"]'));
        $this->assertCount(11, $xpath->query('//li[text()="Geselecteerd B"]'));
        foreach (range(3, 13) as $number) {
            foreach (["Uniek antwoord sectie $number", "Tweede regel $number", "Eerste invoer $number", "Tweede invoer $number"] as $answer) {
                $this->assertStringContainsString($answer, $document->textContent);
            }
        }
        foreach (['0 kg', 'april 2025', 'Onbekend', '2024 (maand onbekend)', 'januari 2025 – heden', 'Niet van toepassing'] as $answer) {
            $this->assertStringContainsString($answer, $document->textContent);
        }
        $this->assertStringNotContainsString('Niet gekozen C', $document->textContent);
    }

    public function test_large_photo_keeps_all_following_photos_and_answers_in_the_pdf_document(): void
    {
        Storage::fake('local');
        $small = UploadedFile::fake()->image('small.png', 20, 20);
        $png = file_get_contents($small->getRealPath());
        // A valid PNG whose base64 attribute exceeds libxml's 10 MB limit.
        $metadata = "Comment\0".str_repeat('x', 500000);
        $chunks = str_repeat(pack('N', strlen($metadata)).'tEXt'.$metadata.pack('N', crc32('tEXt'.$metadata)), 16);
        $large = substr($png, 0, -12).$chunks.substr($png, -12);
        Storage::disk('local')->put('intake/large.png', $large);
        Storage::disk('local')->put('intake/after-large.png', $png);
        $references = [];
        foreach (['large.png', 'after-large.png'] as $name) {
            $photo = IntakeAttachment::create(['booking_id' => $this->booking->id, 'section_key' => 'paard', 'field_key' => 'foto',
                'name' => $name, 'path' => 'intake/'.$name, 'mime' => 'image/png']);
            $references[] = 'attachment:'.$photo->id.':'.$name;
        }
        $this->booking->answers()->create(['section_id' => 'paard', 'field_id' => 'foto', 'value' => json_encode($references)]);
        $last = IntakeQuestionnaire::firstOrFail()->sections()->create(['key' => 'laatste', 'title' => 'Laatste sectie', 'order' => 2, 'active' => true]);
        $last->fields()->create(['key' => 'slotantwoord', 'label' => 'Laatste vraag', 'type' => 'text', 'active' => true]);
        $this->booking->answers()->create(['section_id' => 'laatste', 'field_id' => 'slotantwoord', 'value' => json_encode('Antwoord na alle fotos')]);
        $pdf = app(IntakeAnswersPdf::class);
        $html = $pdf->html($this->booking->fresh());
        $this->assertLessThan(100000, strlen($html));
        $this->assertStringNotContainsString('data:image', $html);
        $renderer = new Dompdf;
        $renderer->loadHtml($html, 'UTF-8');
        $body = $renderer->getDom()->getElementsByTagName('body')->item(0)->textContent;
        $this->assertStringContainsString('after-large.png', $body);
        $this->assertStringContainsString('Antwoord na alle fotos', $body);
        $rendered = $pdf->render($this->booking->fresh());
        $this->assertStringStartsWith('%PDF-', $rendered);
        $this->assertStringContainsString('/Subtype /Image', $rendered);
    }

    public function test_pdf_rejects_a_document_that_was_truncated_during_html_parsing(): void
    {
        $pdf = new class extends IntakeAnswersPdf
        {
            public function html(IntakeBooking $booking): string
            {
                return '<html><body><h1>Alleen het begin van de intake</h1></body></html>';
            }
        };
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('onvolledig opgebouwd');
        $pdf->render($this->booking);
    }

    public function test_pdf_download_uses_the_requested_filename_from_the_submission_and_intake_name(): void
    {
        $this->travelTo(Carbon::parse('2026-09-29T21:00:00Z'));
        $this->booking->answers()->create(['section_id' => 'paard', 'field_id' => 'naam', 'value' => json_encode('Rolo')]);
        $this->upload([$this->submission()])->assertOk();
        $this->actingAs(AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => 'owner', 'active' => true]), 'admin');
        $this->get('/admin/bookings/'.$this->booking->id.'/answers.pdf')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="EquiApp_protocolintake_29-09-2026_Rolo.pdf"');
    }

    public function test_first_submission_uses_server_time_without_resetting_review_on_replay(): void
    {
        $this->booking->update(['status' => 'confirmed']);
        $this->travelTo(Carbon::parse('2026-09-29T22:24:00Z'));
        $op = $this->submission(['submitted_at' => '2001-01-01T00:00:00Z']);
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
        $this->travelTo(Carbon::parse('2026-09-29T22:24:00Z'));
        $op = $this->submission(['submitted_at' => '2001-01-01T00:00:00Z']);
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
        $this->assertNull($message->getHtmlBody());
        $this->assertSame('text', $message->getBody()->getMediaType());
        $this->assertSame('plain', $message->getBody()->getMediaSubtype());
        $this->assertStringContainsString("\n\n".route('admin.bookings.show', $this->booking)."\n", $message->getTextBody());
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
        $this->assertNotSame('Later aangepast', $this->booking->fresh()->notes);
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
        $victimUser = User::factory()->create();
        $victim = IntakeBooking::create(['user_id' => $victimUser->id, 'horse_id' => Horse::create(['owner_id' => $victimUser->id, 'name' => 'Victim horse'])->id]);
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

    public function test_notification_delivery_fields_cannot_be_forged_and_submission_cannot_be_reopened(): void
    {
        $this->upload([$this->submission(['submission_email_status' => 'sent', 'submission_email_sent_at' => now()->toISOString()])])->assertOk();
        $this->assertSame('pending', $this->booking->fresh()->submission_email_status);
        $this->assertNull($this->booking->fresh()->submission_email_sent_at);
        $this->upload([$this->submission(['intake_status' => 'draft', 'submitted_at' => null])])->assertOk();
        $this->artisan('intakes:send-submission-notifications')->assertSuccessful();
        $this->assertSame('submitted', $this->booking->fresh()->intake_status);
        $this->assertCount(1, $this->messages());
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
