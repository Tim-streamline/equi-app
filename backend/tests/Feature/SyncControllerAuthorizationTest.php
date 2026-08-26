<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\ProtocolTemplate;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_removed_focus_topic_table_is_skipped_as_an_unknown_sync_type(): void
    {
        $user = User::factory()->create();

        $this->postSyncAs($user, [[
            'op' => 'PATCH',
            'type' => 'focus_topics',
            'id' => (string) Str::uuid(),
            'data' => ['title' => 'Changed'],
        ]])->assertOk()->assertJson(['applied' => 0, 'skipped' => 1]);
    }

    public function test_owner_can_register_an_intake_for_their_protocol_supplement(): void
    {
        $owner = User::factory()->create();
        $horse = Horse::query()->create([
            'owner_id' => $owner->id,
            'name' => 'Nova',
            'status' => 'active',
        ]);
        $supplement = $this->createProtocolSupplement($horse);
        $intakeId = (string) Str::uuid();

        $this->postSyncAs($owner, [[
            'op' => 'PUT',
            'type' => 'protocol_supplement_intakes',
            'id' => $intakeId,
            'data' => [
                'protocol_phase_supplement_id' => $supplement->id,
                'horse_id' => $horse->id,
                'date' => '2026-08-23',
                'dosage' => '90 g',
                'done' => 1,
                'taken_at' => '2026-08-23T08:30:00.000Z',
            ],
        ]])->assertOk();

        $this->assertDatabaseHas('protocol_supplement_intakes', [
            'id' => $intakeId,
            'protocol_phase_supplement_id' => $supplement->id,
            'horse_id' => $horse->id,
            'dosage' => '90 g',
            'done' => true,
        ]);
    }

    public function test_user_cannot_register_an_intake_for_another_users_protocol_supplement(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $horse = Horse::query()->create([
            'owner_id' => $owner->id,
            'name' => 'Nova',
            'status' => 'active',
        ]);
        $supplement = $this->createProtocolSupplement($horse);

        $this->postSyncAs($attacker, [[
            'op' => 'PUT',
            'type' => 'protocol_supplement_intakes',
            'id' => (string) Str::uuid(),
            'data' => [
                'protocol_phase_supplement_id' => $supplement->id,
                'horse_id' => $horse->id,
                'date' => '2026-08-23',
                'dosage' => '90 g',
                'done' => 1,
                'taken_at' => '2026-08-23T08:30:00.000Z',
            ],
        ]])->assertForbidden();
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

    private function createProtocolSupplement(Horse $horse)
    {
        $template = ProtocolTemplate::query()->create(['name' => 'Darmprotocol']);
        $templatePhase = $template->phases()->create([
            'order' => 0,
            'name' => 'Herstel',
            'required' => true,
        ]);
        $protocol = $horse->protocols()->create([
            'protocol_template_id' => $template->id,
            'protocol_template_name' => $template->name,
            'title' => 'Darmprotocol',
            'status' => 'active',
            'published_at' => now(),
        ]);
        $phase = $protocol->phases()->create([
            'protocol_template_phase_id' => $templatePhase->id,
            'order' => 0,
            'title' => 'Herstel',
            'state' => 'active',
        ]);

        return $phase->supplements()->create([
            'name' => 'Gekookt (bio) lijnzaad',
            'dosage' => '90 g',
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
