<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncControllerEloquentEventsTest extends TestCase
{
    use RefreshDatabase;

    private array $events = [];

    private string $privateKey = '';

    private ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($key, $this->privateKey);
        $publicKey = openssl_pkey_get_details($key)['key'];

        $publicPath = storage_path('framework/testing/powersync_public.pem');
        if (! is_dir(dirname($publicPath))) {
            mkdir(dirname($publicPath), 0777, true);
        }
        file_put_contents($publicPath, $publicKey);

        config([
            'powersync.public_key_path' => $publicPath,
            'powersync.audience' => 'equinova',
            'powersync.issuer' => 'equinova-laravel',
            'powersync.key_id' => 'test-key',
        ]);

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Horse::flushEventListeners();

        parent::tearDown();
    }

    public function test_put_creates_models_through_native_eloquent_events(): void
    {
        $this->recordHorseEvents('creating', 'created', 'saving', 'saved');

        $id = (string) Str::uuid();

        $this->uploadSyncOperation('PUT', $id, [
            'owner_id' => $this->user->id,
            'name' => 'Nova',
            'status' => 'active',
        ])->assertOk();

        $this->assertSame(['saving', 'creating', 'created', 'saved'], $this->events);
        $this->assertDatabaseHas('horses', [
            'id' => $id,
            'owner_id' => $this->user->id,
            'name' => 'Nova',
        ]);
    }

    public function test_put_updates_models_through_native_eloquent_events(): void
    {
        $horse = Horse::query()->create([
            'owner_id' => $this->user->id,
            'name' => 'Nova',
            'status' => 'active',
        ]);

        $this->recordHorseEvents('updating', 'updated', 'saving', 'saved');

        $this->uploadSyncOperation('PUT', $horse->id, [
            'owner_id' => $this->user->id,
            'name' => 'Nova updated',
            'status' => 'active',
        ])->assertOk();

        $this->assertSame(['saving', 'updating', 'updated', 'saved'], $this->events);
        $this->assertDatabaseHas('horses', [
            'id' => $horse->id,
            'name' => 'Nova updated',
        ]);
    }

    public function test_patch_updates_models_through_native_eloquent_events(): void
    {
        $horse = Horse::query()->create([
            'owner_id' => $this->user->id,
            'name' => 'Nova',
            'status' => 'active',
        ]);

        $this->recordHorseEvents('updating', 'updated', 'saving', 'saved');

        $this->uploadSyncOperation('PATCH', $horse->id, [
            'name' => 'Nova updated',
        ])->assertOk();

        $this->assertSame(['saving', 'updating', 'updated', 'saved'], $this->events);
        $this->assertDatabaseHas('horses', [
            'id' => $horse->id,
            'name' => 'Nova updated',
        ]);
    }

    public function test_delete_removes_models_through_native_eloquent_events(): void
    {
        $horse = Horse::query()->create([
            'owner_id' => $this->user->id,
            'name' => 'Nova',
            'status' => 'active',
        ]);

        $this->recordHorseEvents('deleting', 'deleted');

        $this->uploadSyncOperation('DELETE', $horse->id)->assertOk();

        $this->assertSame(['deleting', 'deleted'], $this->events);
        $this->assertDatabaseMissing('horses', [
            'id' => $horse->id,
        ]);
    }

    private function recordHorseEvents(string ...$events): void
    {
        foreach ($events as $event) {
            Horse::{$event}(function () use ($event): void {
                $this->events[] = $event;
            });
        }
    }

    private function uploadSyncOperation(string $operation, string $id, ?array $data = null)
    {
        return $this
            ->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->user))
            ->postJson('/api/sync/upload', [
                'operations' => [[
                    'op' => $operation,
                    'type' => 'horses',
                    'id' => $id,
                    'data' => $data,
                ]],
            ]);
    }

    private function tokenFor(User $user): string
    {
        $now = time();

        return JWT::encode([
            'iss' => config('powersync.issuer'),
            'aud' => config('powersync.audience'),
            'sub' => (string) $user->id,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $this->privateKey, 'RS256', config('powersync.key_id'));
    }
}
