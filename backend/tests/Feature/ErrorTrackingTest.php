<?php

namespace Tests\Feature;

use App\Support\ErrorTracking;
use Illuminate\Support\Facades\Route;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

class ErrorTrackingTest extends TestCase
{
    public function test_laravel_exception_handler_sends_one_sanitized_error_and_preserves_the_500(): void
    {
        $transport = new class implements TransportInterface
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
        $client = ClientBuilder::create([
            'dsn' => 'https://public@example.test/1',
            'before_send' => [ErrorTracking::class, 'sanitize'],
            'send_default_pii' => false,
            'max_request_body_size' => 'none',
        ])->setTransport($transport)->getClient();
        $previousHub = SentrySdk::getCurrentHub();
        SentrySdk::setCurrentHub(new Hub($client));
        try {
            Route::post('/error-tracking-test', fn () => throw new \RuntimeException('Failed for person@example.test password=secret'));
            $this->postJson('/error-tracking-test?token=secret', ['password' => 'secret'])->assertStatus(500);
            $this->assertCount(1, $transport->events);
            $event = $transport->events[0];
            $this->assertSame('backend', $event->getTags()['component']);
            $this->assertSame('Failed for [email] password=[redacted]', $event->getExceptions()[0]->getValue());
            $this->assertNull($event->getUser());
            $this->assertSame([], $event->getExtra());
            $this->assertSame([], $event->getBreadcrumbs());
        } finally {
            SentrySdk::setCurrentHub($previousHub);
        }
    }

    public function test_request_tokens_and_interpolated_sql_are_not_exported(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['method' => 'POST', 'url' => 'https://example.test/web-session/password/reset/secret?email=person@example.test', 'headers' => ['Authorization' => 'Bearer secret'], 'data' => ['password' => 'secret'], 'cookies' => 'secret']);
        $event->setExtra(['private' => 'secret']);
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException("SQLSTATE[23505]: duplicate key (Connection: pgsql, SQL: insert password 'secret')"))]);
        $clean = ErrorTracking::sanitize($event);
        $this->assertSame(['method' => 'POST', 'url' => 'https://example.test/web-session/password/reset/[redacted]'], $clean->getRequest());
        $this->assertSame('SQLSTATE[23505]: duplicate key [SQL details omitted]', $clean->getExceptions()[0]->getValue());
        $this->assertSame('https://example.test/registration/confirm/[redacted]', ErrorTracking::url('https://example.test/registration/confirm/private-uid'));
    }
}
