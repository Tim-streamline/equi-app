<?php

namespace Tests\Feature;

use App\Http\Controllers\IntakeAttachmentController;
use App\Rules\IntakePhotoDimensions;
use App\Support\ErrorTracking;
use App\Support\IntakeUploadDiagnostics;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IntakeUploadDiagnosticsTest extends TestCase
{
    private const ACCOUNT = '11111111-1111-4111-8111-111111111111';

    private const INTAKE = '22222222-2222-4222-8222-222222222222';

    private const ATTACHMENT = '33333333-3333-4333-8333-333333333333';

    private const REQUEST = '44444444-4444-4444-8444-444444444444';

    private $previousHub;

    private $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
        $this->transport = new class implements TransportInterface
        {
            public array $events = [];

            public function send(Event $event): Result
            {
                $this->events[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };
        $this->previousHub = SentrySdk::getCurrentHub();
        SentrySdk::setCurrentHub(new Hub(ClientBuilder::create([
            'dsn' => 'https://public@example.test/1', 'before_send' => [ErrorTracking::class, 'sanitize'],
            'send_default_pii' => false, 'max_request_body_size' => 'none',
        ])->setTransport($this->transport)->getClient()));
        Route::post('/upload-diagnostics-test', function (Request $request) {
            $request->attributes->set('powersync_user_id', self::ACCOUNT);

            return app(IntakeAttachmentController::class)->store($request, self::INTAKE);
        });
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub($this->previousHub);
        parent::tearDown();
    }

    public function test_real_upload_validation_is_reported_with_safe_metadata_in_both_sinks(): void
    {
        $response = $this->withHeader('X-Upload-Request-Id', self::REQUEST)->postJson('/upload-diagnostics-test', [
            'horse_id' => 'private@example.test', 'section' => 'fysiek', 'field' => 'foto-zijaanzicht-rechts',
            'attachment_id' => self::ATTACHMENT, 'account_id' => 'spoofed-account',
            'file' => UploadedFile::fake()->createWithContent('private-horse.jpg', 'private photo contents')->mimeType('text/plain'),
        ]);
        $response->assertUnprocessable()->assertJsonValidationErrors(['horse_id', 'file'])
            ->assertHeader('X-Upload-Request-Id', self::REQUEST)
            ->assertHeader('X-Upload-Validation', 'horse_id:uuid,file:mimes');
        $this->assertCount(1, $this->transport->events);
        $event = $this->transport->events[0];
        $tags = $event->getTags();
        $this->assertSame(self::ACCOUNT, $tags['account_id']);
        $this->assertSame(self::INTAKE, $tags['intake_id']);
        $this->assertSame(self::ATTACHMENT, $tags['attachment_id']);
        $this->assertSame(self::REQUEST, $tags['upload_request_id']);
        $this->assertSame('422', $tags['http_status']);
        $this->assertSame('horse_id:uuid,file:mimes', $tags['validation_failed']);
        $this->assertSame('22', $tags['file_bytes']);
        $this->assertSame('other', $tags['detected_mime']);
        $this->assertNull($event->getUser());
        $this->assertSame([], $event->getExtra());
        Log::shouldHaveReceived('log')->once()->with('warning', '[intake-upload] failed', \Mockery::on(function ($context) {
            $this->assertSame(self::REQUEST, $context['upload_request_id']);
            foreach (['private', 'spoofed', 'contents', 'Authorization'] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode($context));
            }

            return true;
        }));
        $this->assertStringNotContainsString('private', json_encode($tags));
    }

    public function test_old_clients_and_malformed_headers_get_a_generated_reference_and_missing_file_reason(): void
    {
        foreach ([null, 'private@example.test'] as $header) {
            $this->flushHeaders();
            if ($header !== null) {
                $this->withHeader('X-Upload-Request-Id', $header);
            }
            $response = $this->postJson('/upload-diagnostics-test', [
                'horse_id' => self::ACCOUNT, 'section' => 'fysiek', 'field' => 'foto-zijaanzicht-rechts',
            ]);
            $response->assertUnprocessable()->assertJsonValidationErrors('file')->assertHeader('X-Upload-Validation', 'file:required');
            $this->assertTrue(Str::isUuid($response->headers->get('X-Upload-Request-Id')));
        }
        $this->assertCount(2, $this->transport->events);
        $this->assertSame('false', $this->transport->events[0]->getTags()['file_present']);
    }

    public function test_dimension_rule_and_original_file_dimensions_are_recorded(): void
    {
        $file = UploadedFile::fake()->image('private-name.jpg', 7681, 1);
        $request = Request::create('/upload-diagnostics-test', 'POST', [], [], ['file' => $file]);
        $response = app(IntakeUploadDiagnostics::class)->run($request, self::INTAKE,
            fn () => Validator::make(['file' => $file], ['file' => [new IntakePhotoDimensions]])->validate());
        $this->assertSame(422, $response->getStatusCode());
        $tags = $this->transport->events[0]->getTags();
        $this->assertSame('file:dimensions', $tags['validation_failed']);
        $this->assertSame('7681', $tags['image_width']);
        $this->assertSame('1', $tags['image_height']);
    }

    public function test_invalid_php_upload_reports_the_upload_error_code(): void
    {
        $file = new UploadedFile(__FILE__, 'private.jpg', 'image/jpeg', UPLOAD_ERR_PARTIAL, true);
        $request = Request::create('/upload-diagnostics-test', 'POST', [], [], ['file' => $file]);
        app(IntakeUploadDiagnostics::class)->run($request, self::INTAKE,
            fn () => Validator::make(['file' => $file], ['file' => ['required', 'file']])->validate());
        $tags = $this->transport->events[0]->getTags();
        $this->assertSame('file:uploaded', $tags['validation_failed']);
        $this->assertSame((string) UPLOAD_ERR_PARTIAL, $tags['file_upload_error']);
        $this->assertSame('false', $tags['file_valid']);
    }

    public function test_success_is_logged_without_a_better_stack_error_and_logging_cannot_break_uploads(): void
    {
        $request = Request::create('/upload-diagnostics-test', 'POST');
        $diagnostics = app(IntakeUploadDiagnostics::class);
        foreach ([201, 200] as $status) {
            $response = $diagnostics->run($request, self::INTAKE, fn () => response()->json(['value' => 'original-value'], $status));
            $this->assertSame($status, $response->getStatusCode());
            $this->assertSame('original-value', $response->getData(true)['value']);
        }
        $this->assertCount(0, $this->transport->events);
        Log::shouldHaveReceived('log')->twice()->with('info', '[intake-upload] completed', \Mockery::type('array'));
        Log::shouldReceive('log')->andThrow(new \RuntimeException('log unavailable'));
        $this->assertSame(201, $diagnostics->run($request, self::INTAKE, fn () => response()->json([], 201))->getStatusCode());
        $error = new HttpException(409, 'private error details');
        try {
            $diagnostics->run($request, self::INTAKE, fn () => throw $error);
            $this->fail('Expected original exception');
        } catch (\Throwable $caught) {
            $this->assertSame($error, $caught);
        }
        $this->assertSame('409', $this->transport->events[0]->getTags()['http_status']);
    }
}
