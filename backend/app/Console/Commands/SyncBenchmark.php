<?php

namespace App\Console\Commands;

use Firebase\JWT\JWT;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use JsonException;

class SyncBenchmark extends Command
{
    protected $signature = 'sync:benchmark
        {--payload=database/fixtures/sync-benchmark-payload.json : Path to the JSON sync payload, relative to the app base path unless absolute}
        {--url= : Sync upload endpoint URL}
        {--requests=20 : Number of POST requests to send}
        {--token= : Bearer token to use instead of minting a local PowerSync JWT}
        {--user=sync-benchmark : JWT subject used when minting a local token}
        {--timeout=30 : HTTP timeout in seconds}';

    protected $description = 'Benchmark the sync upload endpoint by repeatedly posting a saved payload';

    public function handle(): int
    {
        $requests = (int) $this->option('requests');
        if ($requests < 1) {
            $this->error('The --requests option must be at least 1.');

            return self::FAILURE;
        }

        $payloadPath = $this->resolvePayloadPath((string) $this->option('payload'));
        if (! is_file($payloadPath)) {
            $this->error("Payload file not found: {$payloadPath}");

            return self::FAILURE;
        }

        try {
            $payload = json_decode(file_get_contents($payloadPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Payload is not valid JSON: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! is_array($payload) || ! isset($payload['operations']) || ! is_array($payload['operations'])) {
            $this->error('Payload must be a JSON object with an operations array.');

            return self::FAILURE;
        }

        $url = $this->endpointUrl();
        $token = $this->option('token') ?: $this->mintToken((string) $this->option('user'));
        if (! $token) {
            return self::FAILURE;
        }
        $timeout = max(1, (int) $this->option('timeout'));
        $durations = [];

        $this->line("Endpoint: {$url}");
        $this->line("Payload: {$payloadPath}");
        $this->line('Operations per request: '.count($payload['operations']));
        $this->line("Requests: {$requests}");

        for ($i = 1; $i <= $requests; $i++) {
            $startedAt = hrtime(true);
            $response = Http::acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->withToken($token)
                ->post($url, $payload);
            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $durations[] = $durationMs;

            if ($response->failed()) {
                $this->error(sprintf(
                    'Request %d failed with HTTP %d after %.2f ms: %s',
                    $i,
                    $response->status(),
                    $durationMs,
                    $response->body(),
                ));

                return self::FAILURE;
            }
        }

        $totalMs = array_sum($durations);
        $averageMs = $totalMs / $requests;

        $this->newLine();
        $this->info('Sync benchmark complete.');
        $this->line(sprintf('Total time: %.2f ms', $totalMs));
        $this->line(sprintf('Average time per request: %.2f ms', $averageMs));
        $this->line(sprintf('Fastest request: %.2f ms', min($durations)));
        $this->line(sprintf('Slowest request: %.2f ms', max($durations)));

        return self::SUCCESS;
    }

    private function resolvePayloadPath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }

    private function endpointUrl(): string
    {
        $url = $this->option('url');
        if ($url) {
            return (string) $url;
        }

        return rtrim((string) config('app.url'), '/').'/api/sync/upload';
    }

    private function mintToken(string $userId): ?string
    {
        $privateKeyPath = config('powersync.private_key_path');
        if (! is_file($privateKeyPath)) {
            $this->error('PowerSync private key not found. Run `php artisan powersync:generate-keys` or pass --token.');

            return null;
        }

        $now = time();

        return JWT::encode(
            [
                'iss' => config('powersync.issuer'),
                'aud' => config('powersync.audience'),
                'sub' => $userId,
                'iat' => $now,
                'exp' => $now + (int) config('powersync.token_ttl'),
            ],
            file_get_contents($privateKeyPath),
            'RS256',
            config('powersync.key_id'),
        );
    }
}
