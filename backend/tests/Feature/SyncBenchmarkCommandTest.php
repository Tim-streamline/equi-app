<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncBenchmarkCommandTest extends TestCase
{
    public function test_saved_benchmark_payload_contains_100_operations(): void
    {
        $payload = json_decode(file_get_contents(base_path('database/fixtures/sync-benchmark-payload.json')), true);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('operations', $payload);
        $this->assertCount(100, $payload['operations']);
    }

    public function test_sync_benchmark_posts_payload_requested_number_of_times_and_reports_average(): void
    {
        Http::fake([
            'https://sync.test/api/sync/upload' => Http::response(['applied' => 100], 200),
        ]);

        $this
            ->artisan('sync:benchmark', [
                '--url' => 'https://sync.test/api/sync/upload',
                '--payload' => 'database/fixtures/sync-benchmark-payload.json',
                '--requests' => 3,
                '--token' => 'test-token',
            ])
            ->expectsOutputToContain('Requests: 3')
            ->expectsOutputToContain('Average time per request:')
            ->assertSuccessful();

        Http::assertSentCount(3);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://sync.test/api/sync/upload'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && count($request->data()['operations'] ?? []) === 100;
        });
    }
}
