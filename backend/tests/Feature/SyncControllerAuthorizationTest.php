<?php

namespace Tests\Feature;

use App\Models\FocusTopic;
use App\Models\Horse;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncControllerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey = '';

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
    }

    public function test_user_cannot_update_another_users_horse(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $horse = Horse::query()->create([
            'owner_id' => $owner->id,
            'name' => 'Nova',
            'status' => 'active',
        ]);

        $this->postSyncAs($attacker, [[
            'op' => 'PATCH',
            'type' => 'horses',
            'id' => $horse->id,
            'data' => ['name' => 'Stolen'],
        ]])->assertForbidden();

        $this->assertDatabaseHas('horses', [
            'id' => $horse->id,
            'owner_id' => $owner->id,
            'name' => 'Nova',
        ]);
    }

    public function test_owner_can_update_their_own_horse(): void
    {
        $owner = User::factory()->create();
        $horse = Horse::query()->create([
            'owner_id' => $owner->id,
            'name' => 'Nova',
            'status' => 'active',
        ]);

        $this->postSyncAs($owner, [[
            'op' => 'PATCH',
            'type' => 'horses',
            'id' => $horse->id,
            'data' => ['name' => 'Nova Updated'],
        ]])->assertOk();

        $this->assertDatabaseHas('horses', [
            'id' => $horse->id,
            'owner_id' => $owner->id,
            'name' => 'Nova Updated',
        ]);
    }

    public function test_client_cannot_write_reference_tables_through_sync(): void
    {
        $user = User::factory()->create();
        $topic = FocusTopic::query()->create([
            'slug' => 'jeuk',
            'title' => 'Jeukklachten',
            'order' => 0,
        ]);

        $this->postSyncAs($user, [[
            'op' => 'PATCH',
            'type' => 'focus_topics',
            'id' => $topic->id,
            'data' => ['title' => 'Changed'],
        ]])->assertForbidden();

        $this->assertDatabaseHas('focus_topics', [
            'id' => $topic->id,
            'title' => 'Jeukklachten',
        ]);
    }

    public function test_unauthorized_operation_aborts_entire_batch(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $ownHorse = Horse::query()->create([
            'owner_id' => $attacker->id,
            'name' => 'Own Horse',
            'status' => 'active',
        ]);
        $otherHorse = Horse::query()->create([
            'owner_id' => $owner->id,
            'name' => 'Other Horse',
            'status' => 'active',
        ]);

        $this->postSyncAs($attacker, [
            [
                'op' => 'PATCH',
                'type' => 'horses',
                'id' => $ownHorse->id,
                'data' => ['name' => 'Should Roll Back'],
            ],
            [
                'op' => 'PATCH',
                'type' => 'horses',
                'id' => $otherHorse->id,
                'data' => ['name' => 'Should Be Blocked'],
            ],
        ])->assertForbidden();

        $this->assertDatabaseHas('horses', [
            'id' => $ownHorse->id,
            'name' => 'Own Horse',
        ]);
        $this->assertDatabaseHas('horses', [
            'id' => $otherHorse->id,
            'name' => 'Other Horse',
        ]);
    }

    private function postSyncAs(User $user, array $operations)
    {
        return $this
            ->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/sync/upload', ['operations' => $operations]);
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
