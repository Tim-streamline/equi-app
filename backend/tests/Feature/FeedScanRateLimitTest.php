<?php

namespace Tests\Feature;

use App\Http\Controllers\FeedScanController;
use App\Http\Middleware\AuthenticatePowerSyncJwt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FeedScanRateLimitTest extends TestCase
{
    private string $userId = '00000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        // Mirror JWT authentication: identity is a request attribute, not a session user.
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($this->userId)
        {
            public function __construct(private string $userId) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->userId);

                return $next($request);
            }
        });
        $this->mock(FeedScanController::class, function ($mock) {
            foreach (['store', 'upload', 'analyse', 'check', 'confirm'] as $action) {
                $mock->shouldReceive($action)->andReturnUsing(fn () => response()->noContent());
            }
        });
    }

    public static function limitedActions(): array
    {
        return [
            'create' => ['', 20, 60],
            'upload' => ['/photos', 60, 1],
            'analyse' => ['/analyse', 10, 60],
            'check' => ['/check', 30, 60],
            'confirm' => ['/confirm', 30, 60],
        ];
    }

    #[DataProvider('limitedActions')]
    public function test_limits_follow_the_user_and_preserve_each_actions_allowance(string $action, int $attempts, int $minutes): void
    {
        $base = '/api/feed-scanner/submissions';
        $id = '/00000000-0000-4000-8000-000000000003';
        $url = $base.($action === '' ? '' : $id.$action);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1']);
        for ($i = 0; $i < $attempts; $i++) {
            $this->postJson($url)->assertNoContent();
        }
        $this->postJson($url)->assertTooManyRequests();

        // Another user on the same network retains their own allowance.
        $this->userId = '00000000-0000-4000-8000-000000000002';
        $this->postJson($url)->assertNoContent();

        // Changing networks does not reset the first user's exhausted allowance.
        $this->userId = '00000000-0000-4000-8000-000000000001';
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2']);
        $this->postJson($url)->assertTooManyRequests();

        // Different scanner actions keep separate buckets.
        $this->postJson($action === '' ? $base.$id.'/check' : $base)->assertNoContent();
        $this->travel($minutes * 60 + 1)->seconds();
        $this->postJson($url)->assertNoContent();
    }
}
