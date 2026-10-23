<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\SubscriptionAccess;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntakeSyncAuthorizationTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\HasSubscription;

    private string $privateKey = '';

    public function test_stale_offline_removed_choices_are_acknowledged_without_restoring_the_answer(): void
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $q = IntakeQuestionnaire::create(['slug' => 'protocol-intake', 'name' => 'Intake']);
        $section = $q->sections()->create(['key' => 'paard', 'title' => 'Paard']);
        $section->fields()->create(['key' => 'choice', 'label' => 'Choice', 'type' => 'radio', 'options' => ['current']]);
        $id = (string) Str::uuid();
        $op = ['op' => 'PUT', 'type' => 'intake_answers', 'id' => $id,
            'data' => ['response_id' => $booking->id, 'section_id' => 'paard', 'field_id' => 'choice', 'value' => '"removed"']];
        $this->postSyncAs($user, [$op])->assertOk();
        $this->assertDatabaseMissing('intake_answers', ['id' => $id]);
        $this->postSyncAs($user, [
            ['op' => 'PATCH', 'type' => 'intake_answers', 'id' => $id, 'data' => $op['data']],
            ['op' => 'DELETE', 'type' => 'intake_answers', 'id' => $id],
        ])->assertOk();
        $this->assertDatabaseMissing('intake_answers', ['id' => $id]);
        $op['data']['value'] = '"current"';
        $this->postSyncAs($user, [$op])->assertOk();
        $this->assertDatabaseHas('intake_answers', ['id' => $id, 'value' => '"current"']);
    }

    private function cleanedAnswer(): array
    {
        $owner = User::factory()->create();
        $horse = Horse::create(['owner_id' => $owner->id, 'name' => 'Offline correction']);
        $booking = IntakeBooking::create(['user_id' => $owner->id, 'horse_id' => $horse->id]);
        $q = IntakeQuestionnaire::create(['slug' => 'protocol-intake', 'name' => 'Intake']);
        $section = $q->sections()->create(['key' => 'moved', 'title' => 'Moved questions']);
        $field = $section->fields()->create(['key' => 'choice', 'label' => 'Choice', 'type' => 'radio',
            'answer_section_key' => 'original', 'options' => ['current', 'urgent'],
            'answer_aliases' => ['historical' => 'current'],
            'submission_blocks' => [['answer' => 'urgent', 'message' => 'Contact your vet.']]]);
        $answer = $booking->answers()->create(['section_id' => 'original', 'field_id' => 'choice', 'value' => '"removed"']);
        app(\App\Support\IntakeObsoleteAnswers::class)->run();
        $this->assertDatabaseMissing('intake_answers', ['id' => $answer->id]);

        return [$owner, $booking, $field, ['op' => 'PATCH', 'type' => 'intake_answers', 'id' => $answer->id,
            'data' => ['response_id' => $booking->id, 'section_id' => 'original', 'field_id' => 'choice', 'value' => '"current"']]];
    }

    public function test_valid_offline_corrections_survive_cleanup_and_retries_without_rewriting_aliases(): void
    {
        [$owner, $booking, $field, $op] = $this->cleanedAnswer();
        foreach (['current', 'historical'] as $value) {
            $op['data']['value'] = json_encode($value);
            $this->postSyncAs($owner, [$op])->assertOk()->assertJsonPath('applied', 1);
            $this->postSyncAs($owner, [$op])->assertOk();
            $this->assertDatabaseHas('intake_answers', ['id' => $op['id'], 'response_id' => $booking->id, 'value' => json_encode($value)]);
            $this->assertDatabaseCount('intake_answers', 1);
            $booking->answers()->delete();
        }
    }

    public function test_recovery_keeps_obsolete_or_unconfigured_answers_deleted(): void
    {
        [$owner, $booking, $field, $op] = $this->cleanedAnswer();
        foreach (['removed', 'unknown option', null, ''] as $value) {
            $op['data']['value'] = json_encode($value);
            $this->postSyncAs($owner, [$op])->assertOk()->assertJsonPath('applied', 0);
            $this->assertDatabaseMissing('intake_answers', ['id' => $op['id']]);
        }
        $op['data']['value'] = '"current"';
        $field->update(['active' => false]);
        $this->postSyncAs($owner, [$op])->assertOk()->assertJsonPath('applied', 0);
        $this->assertDatabaseCount('intake_answers', 0);
    }

    public function test_correction_recovers_if_cleanup_removes_the_row_before_the_booking_lock_is_acquired(): void
    {
        [$owner, $booking, $field, $op] = $this->cleanedAnswer();
        \App\Models\IntakeAnswer::forceCreate(['id' => $op['id'], ...$op['data'], 'value' => '"removed"']);
        $deleted = false;
        \Illuminate\Support\Facades\DB::listen(function ($query) use ($booking, $op, &$deleted) {
            if (! $deleted && str_contains($query->sql, '"intake_bookings"') && str_contains($query->sql, 'for update')
                && in_array($booking->id, $query->bindings, true)) {
                $deleted = true;
                \Illuminate\Support\Facades\DB::table('intake_answers')->where('id', $op['id'])->delete();
            }
        });
        $this->postSyncAs($owner, [$op])->assertOk()->assertJsonPath('applied', 1);
        $this->assertTrue($deleted);
        $this->assertDatabaseHas('intake_answers', ['id' => $op['id'], 'value' => '"current"']);
    }

    public function test_unidentifiable_offline_correction_is_not_silently_acknowledged(): void
    {
        [$owner, $booking, $field, $op] = $this->cleanedAnswer();
        $op['data'] = ['value' => '"current"'];
        $this->postSyncAs($owner, [$op])->assertStatus(409);
        $this->assertDatabaseCount('intake_answers', 0);
    }

    public function test_metadata_only_patch_does_not_restore_a_cleaned_up_answer(): void
    {
        [$owner, $booking, $field, $op] = $this->cleanedAnswer();
        unset($op['data']['value']);
        $op['data']['updated_at'] = now()->toISOString();
        $this->postSyncAs($owner, [$op])->assertOk()->assertJsonPath('applied', 0);
        $this->assertDatabaseCount('intake_answers', 0);
    }

    public function test_recovery_cannot_reopen_a_cancelled_intake(): void
    {
        [$owner, $booking, $field, $op] = $this->cleanedAnswer();
        $booking->update(['status' => 'cancelled']);
        $this->postSyncAs($owner, [$op])->assertForbidden();
        $this->assertDatabaseCount('intake_answers', 0);
    }

    public function test_recovery_requires_both_intake_and_current_horse_ownership(): void
    {
        [$owner, $booking, $field, $op] = $this->cleanedAnswer();
        $other = User::factory()->create();
        $this->postSyncAs($other, [$op])->assertForbidden();
        $booking->horse->update(['owner_id' => $other->id]);
        $this->postSyncAs($owner, [$op])->assertForbidden();
        $this->postSyncAs($other, [$op])->assertForbidden();
        $this->assertDatabaseCount('intake_answers', 0);
    }

    public function test_recovery_never_changes_a_submitted_or_completed_intake(): void
    {
        [$owner, $booking, $field, $op] = $this->cleanedAnswer();
        foreach (['submitted_at', 'completed_at'] as $timestamp) {
            $booking->forceFill(['submitted_at' => null, 'completed_at' => null, $timestamp => now()])->save();
            $before = $booking->fresh()->getRawOriginal();
            $this->postSyncAs($owner, [$op])->assertOk()->assertJsonPath('applied', 0);
            $this->assertDatabaseCount('intake_answers', 0);
            $this->assertSame($before, $booking->fresh()->getRawOriginal());
        }
    }

    public function test_recovery_reuses_a_replacement_answer_and_still_enforces_submission_blocks(): void
    {
        [$owner, $booking, $field, $op] = $this->cleanedAnswer();
        $replacement = $booking->answers()->create(['section_id' => 'original', 'field_id' => 'choice', 'value' => '"current"']);
        $op['data']['value'] = '"urgent"';
        $this->postSyncAs($owner, [$op])->assertOk();
        $this->assertDatabaseCount('intake_answers', 1);
        $this->assertSame('"urgent"', $replacement->fresh()->value);
        $this->postSyncAs($owner, [['op' => 'PATCH', 'type' => 'intake_bookings', 'id' => $booking->id,
            'data' => ['intake_status' => 'submitted']]])->assertUnprocessable();
        $this->assertNull($booking->fresh()->submitted_at);
        $this->assertSame('"urgent"', $replacement->fresh()->value);
    }

    public function test_sync_preserves_valid_aliases_verbatim_and_discards_obsolete_puts_and_patches(): void
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Aliases']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $q = IntakeQuestionnaire::create(['slug' => 'protocol-intake', 'name' => 'Intake']);
        $section = $q->sections()->create(['key' => 'current-section', 'title' => 'Current section']);
        $cases = [
            ['radio', 'Combinatie van beide', 'removed'],
            ['multi', ['current', 'Combinatie van beide'], ['Combinatie van beide', 'removed']],
            ['number', 'old unknown', 'removed'],
            ['radio', 'current', 'broken alias'],
        ];
        foreach ($cases as $index => [$type, $historical, $obsolete]) {
            $key = 'alias-'.$index;
            $section->fields()->create(['key' => $key, 'label' => $key, 'type' => $type, 'answer_section_key' => 'original',
                'options' => ['Een combinatie van beide', 'current', 'unknown'],
                'answer_aliases' => ['Combinatie van beide' => 'Een combinatie van beide', 'old unknown' => 'unknown', 'broken alias' => 'removed target']]);
            $id = (string) Str::uuid();
            $encoded = json_encode($historical);
            $op = ['op' => 'PUT', 'type' => 'intake_answers', 'id' => $id,
                'data' => ['response_id' => $booking->id, 'section_id' => 'original', 'field_id' => $key, 'value' => $encoded]];
            $this->postSyncAs($user, [$op])->assertOk();
            $this->assertDatabaseHas('intake_answers', ['id' => $id, 'value' => $encoded]);
            $this->postSyncAs($user, [['op' => 'PATCH', 'type' => 'intake_answers', 'id' => $id, 'data' => ['value' => $encoded]]])->assertOk();
            $this->assertDatabaseHas('intake_answers', ['id' => $id, 'value' => $encoded]);
            $this->postSyncAs($user, [['op' => 'PATCH', 'type' => 'intake_answers', 'id' => $id, 'data' => ['value' => json_encode($obsolete)]]])->assertOk();
            $this->assertDatabaseMissing('intake_answers', ['id' => $id]);
            $op['data']['value'] = json_encode($obsolete);
            $this->postSyncAs($user, [$op])->assertOk();
            $this->assertDatabaseMissing('intake_answers', ['id' => $id]);
            $op['data']['value'] = $encoded;
            $this->postSyncAs($user, [$op])->assertOk();
            $this->assertDatabaseHas('intake_answers', ['id' => $id, 'value' => $encoded]);
        }
    }

    public function test_existing_intake_can_sync_answers_and_submit_without_plus_but_cannot_create_another(): void
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $q = IntakeQuestionnaire::create(['slug' => 'protocol-intake', 'name' => 'Intake', 'active' => true]);
        $s = $q->sections()->create(['key' => 'paard', 'title' => 'Paard', 'active' => true]);
        $s->fields()->create(['key' => 'naam', 'label' => 'Naam', 'type' => 'text', 'active' => true]);
        $id = (string) Str::uuid();
        $this->postSyncAs($user, [
            ['op' => 'PUT', 'type' => 'intake_bookings', 'id' => $booking->id, 'data' => ['user_id' => $user->id, 'horse_id' => $horse->id]],
            ['op' => 'PUT', 'type' => 'intake_answers', 'id' => $id, 'data' => ['response_id' => $booking->id, 'section_id' => 'paard', 'field_id' => 'naam', 'value' => '"Offline Nova"']],
        ])->assertOk();
        $this->postSyncAs($user, [
            ['op' => 'PATCH', 'type' => 'intake_answers', 'id' => $id, 'data' => ['value' => '"Updated offline"']],
            ['op' => 'PATCH', 'type' => 'intake_bookings', 'id' => $booking->id, 'data' => ['intake_status' => 'submitted']],
        ])->assertOk();
        $this->assertDatabaseHas('intake_answers', ['id' => $id, 'value' => '"Updated offline"']);
        $this->assertNotNull($booking->fresh()->submitted_at);
        $this->postSyncAs($user, [['op' => 'DELETE', 'type' => 'intake_answers', 'id' => $id]])->assertOk();
        $this->postSyncAs($user, [['op' => 'PUT', 'type' => 'intake_bookings', 'id' => (string) Str::uuid(),
            'data' => ['user_id' => $user->id, 'horse_id' => $horse->id]]])->assertForbidden();
        $this->assertDatabaseCount('intake_bookings', 1);
        $this->assertDatabaseHas('intake_answers', ['id' => $id, 'value' => '"Updated offline"']);
    }

    public function test_offline_writes_still_reject_foreign_bookings_and_transferred_horses_without_plus(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $horse = Horse::create(['owner_id' => $owner->id, 'name' => 'Nova']);
        $booking = IntakeBooking::create(['user_id' => $owner->id, 'horse_id' => $horse->id]);
        $operation = ['op' => 'PUT', 'type' => 'intake_answers', 'id' => (string) Str::uuid(),
            'data' => ['response_id' => $booking->id, 'section_id' => 'paard', 'field_id' => 'naam', 'value' => '"No"']];
        $this->postSyncAs($other, [$operation])->assertForbidden();
        $horse->update(['owner_id' => $other->id]);
        $this->postSyncAs($owner, [$operation])->assertForbidden();
        $this->postSyncAs($other, [$operation])->assertForbidden();
        $this->assertDatabaseCount('intake_answers', 0);
    }

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
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $responseId = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id])->id;
        $answerId = (string) Str::uuid();

        $this->postSyncAs($user, [
            [
                'op' => 'PUT',
                'type' => 'intake_bookings',
                'id' => $responseId,
                'data' => ['user_id' => $user->id, 'horse_id' => $horse->id, 'intake_status' => 'draft'],
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

        $this->assertDatabaseHas('intake_bookings', ['id' => $responseId, 'user_id' => $user->id]);
        $this->assertDatabaseHas('intake_answers', ['id' => $answerId, 'field_id' => 'naam']);
    }

    public function test_user_cannot_create_intake_response_for_another_user(): void
    {
        $attacker = User::factory()->create();
        $victim = User::factory()->create();

        $this->postSyncAs($attacker, [[
            'op' => 'PUT',
            'type' => 'intake_bookings',
            'id' => (string) Str::uuid(),
            'data' => ['user_id' => $victim->id, 'intake_status' => 'draft'],
        ]])->assertForbidden();

        $this->assertDatabaseMissing('intake_bookings', ['user_id' => $victim->id]);
    }

    public function test_agriculture_answers_survive_authenticated_mobile_upload_and_reload(): void
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $response = IntakeBooking::query()->create(['user_id' => $user->id, 'horse_id' => $horse->id, 'intake_status' => 'draft']);
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
        $response = IntakeBooking::query()->create([
            'user_id' => $owner->id,
            'horse_id' => Horse::create(['owner_id' => $owner->id, 'name' => 'Victim horse'])->id,
            'intake_status' => 'draft',
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

    public function test_existing_answer_cannot_be_reassigned_to_steal_it(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $victimBooking = IntakeBooking::create(['user_id' => $owner->id, 'horse_id' => Horse::create(['owner_id' => $owner->id, 'name' => 'Test horse'])->id]);
        $ownBooking = IntakeBooking::create(['user_id' => $attacker->id, 'horse_id' => Horse::create(['owner_id' => $attacker->id, 'name' => 'Test horse'])->id]);
        $answer = $victimBooking->answers()->create(['section_id' => 'paard', 'field_id' => 'naam', 'value' => '"Nova"']);
        $this->postSyncAs($attacker, [['op' => 'PATCH', 'type' => 'intake_answers', 'id' => $answer->id,
            'data' => ['response_id' => $ownBooking->id, 'value' => '"Stolen"']]])->assertForbidden();
        $this->assertSame($victimBooking->id, $answer->fresh()->response_id);
    }

    public function test_old_client_intake_writes_require_an_update_instead_of_being_silently_lost(): void
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $this->postSyncAs($user, [['op' => 'PUT', 'type' => 'intake_responses', 'id' => (string) Str::uuid(),
            'data' => ['user_id' => $user->id, 'status' => 'draft']]])->assertStatus(409);
    }

    private function postSyncAs(User $user, array $operations)
    {
        return $this
            ->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/sync/upload', ['operations' => $operations]);
    }

    private function tokenFor(User $user): string
    {
        if (! app(SubscriptionAccess::class)->active($user->id)) {
            $this->subscribe($user);
        }
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
