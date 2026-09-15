<?php

namespace Tests\Feature;

use App\Models\IntakeResponse;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntakeSyncAuthorizationTest extends TestCase
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

    public function test_owner_can_create_their_own_intake_response_and_answer(): void
    {
        $user = User::factory()->create();
        $responseId = (string) Str::uuid();
        $answerId = (string) Str::uuid();

        $this->postSyncAs($user, [
            [
                'op' => 'PUT',
                'type' => 'intake_responses',
                'id' => $responseId,
                'data' => ['user_id' => $user->id, 'status' => 'draft'],
            ],
            [
                'op' => 'PUT',
                'type' => 'intake_answers',
                'id' => $answerId,
                'data' => [
                    'response_id' => $responseId,
                    'section_id' => 'paard',
                    'field_id' => 'naam',
                    'value' => '"Nova"',
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('intake_responses', ['id' => $responseId, 'user_id' => $user->id]);
        $this->assertDatabaseHas('intake_answers', ['id' => $answerId, 'field_id' => 'naam']);
    }

    public function test_user_cannot_create_intake_response_for_another_user(): void
    {
        $attacker = User::factory()->create();
        $victim = User::factory()->create();

        $this->postSyncAs($attacker, [[
            'op' => 'PUT',
            'type' => 'intake_responses',
            'id' => (string) Str::uuid(),
            'data' => ['user_id' => $victim->id, 'status' => 'draft'],
        ]])->assertForbidden();

        $this->assertDatabaseMissing('intake_responses', ['user_id' => $victim->id]);
    }

    public function test_agriculture_answers_survive_authenticated_mobile_upload_and_reload(): void
    {
        $user = User::factory()->create();
        $response = IntakeResponse::query()->create(['user_id' => $user->id, 'status' => 'draft']);
        $answers = [
            'landbouw-nabij' => 'Ja',
            'landbouw-afstand' => 'Minder dan 50 meter',
            'landbouw-gewas' => 'maïs',
            'landbouw-bespoten' => 'Ja',
            'landbouw-middelen' => ['Onkruidbestrijding / herbicide', 'Schimmelbestrijding / fungicide'],
            'landbouw-frequentie' => 'Enkele keren per jaar',
            'landbouw-nevel' => 'Beide',
            'landbouw-water' => 'Weet ik niet',
            'landbouw-klachten' => 'Ja',
            'landbouw-klachten-toelichting' => 'Geïrriteerde ogen na werkzaamheden.',
            'omgeving-overig' => 'Ook een boomgaard naast de weide.',
        ];
        $operations = [];
        foreach ($answers as $field => $value) {
            $operations[] = [
                'op' => 'PUT', 'type' => 'intake_answers', 'id' => (string) Str::uuid(),
                'data' => [
                    'response_id' => $response->id, 'section_id' => 'huisvesting',
                    'field_id' => $field, 'value' => json_encode($value, JSON_THROW_ON_ERROR),
                ],
            ];
        }

        $this->postSyncAs($user, $operations)->assertOk()->assertJsonPath('applied', 11);
        $reloaded = $response->fresh()->answers->mapWithKeys(fn ($answer) => [
            $answer->field_id => json_decode($answer->value, true, flags: JSON_THROW_ON_ERROR),
        ])->all();
        $this->assertEquals($answers, $reloaded);
    }

    public function test_user_cannot_write_answer_on_another_users_response(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $response = IntakeResponse::query()->create([
            'user_id' => $owner->id,
            'status' => 'draft',
        ]);

        $this->postSyncAs($attacker, [[
            'op' => 'PUT',
            'type' => 'intake_answers',
            'id' => (string) Str::uuid(),
            'data' => [
                'response_id' => $response->id,
                'section_id' => 'paard',
                'field_id' => 'naam',
                'value' => '"Stolen"',
            ],
        ]])->assertForbidden();

        $this->assertDatabaseMissing('intake_answers', ['response_id' => $response->id]);
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
