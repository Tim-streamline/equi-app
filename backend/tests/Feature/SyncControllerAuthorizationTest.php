<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\HorseShare;
use App\Models\LibraryBookmark;
use App\Models\LibraryItem;
use App\Models\LibraryProgress;
use App\Models\NotificationPreference;
use App\Models\Observation;
use App\Models\ObservationPhoto;
use App\Models\ProtocolTemplate;
use App\Models\ScanIngredient;
use App\Models\ScanResult;
use App\Models\Therapist;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncControllerAuthorizationTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\HasSubscription;

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
        $this->travelTo(now()->setDate(2026, 8, 23)->setTime(12, 0));
        $owner = User::factory()->create();
        $horse = Horse::query()->create([
            'owner_id' => $owner->id,
            'name' => 'Nova',
            'status' => 'active',
        ]);
        $supplement = $this->createProtocolSupplement($horse);
        $supplement->phase->protocol->update(['started_at' => '2026-08-23']);
        $week = $supplement->phase->weeks()->create(['number' => 1, 'protocol_week_number' => 1]);
        $supplement->weeks()->create(['protocol_phase_week_id' => $week->id]);
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

    public function test_new_bookings_cannot_use_archived_therapists_but_history_remains_editable(): void
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova', 'status' => 'active']);
        $therapist = Therapist::create(['name' => 'Archived therapist']);
        $therapist->forceFill(['archived_at' => now()])->save();
        $id = \App\Models\IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id])->id;
        $operation = ['op' => 'PUT', 'type' => 'intake_bookings', 'id' => $id,
            'data' => ['user_id' => $user->id, 'horse_id' => $horse->id, 'therapist_id' => $therapist->id, 'scheduled_at' => now()->toDateTimeString(), 'status' => 'pending']];
        $this->postSyncAs($user, [$operation])->assertUnprocessable()->assertJsonValidationErrors('therapist_id');
        $this->assertDatabaseHas('intake_bookings', ['id' => $id, 'therapist_id' => null]);
        $therapist->forceFill(['archived_at' => null])->save();
        $this->postSyncAs($user, [$operation])->assertOk();
        $therapist->forceFill(['archived_at' => now()])->save();
        $this->postSyncAs($user, [['op' => 'PATCH', 'type' => 'intake_bookings', 'id' => $id,
            'data' => ['therapist_id' => $therapist->id, 'notes' => 'Historical booking']]])->assertOk();
        $this->assertDatabaseHas('intake_bookings', ['id' => $id, 'therapist_id' => $therapist->id, 'notes' => 'Historical booking']);
    }

    public function test_horse_shares_are_server_managed_even_for_the_horses_owner(): void
    {
        $owner = User::factory()->create();
        $victim = User::factory()->create();
        $horse = Horse::create(['owner_id' => $owner->id, 'name' => 'Nova', 'status' => 'active']);
        $share = HorseShare::create(['horse_id' => $horse->id, 'grantee_user_id' => null, 'role' => 'read_only']);
        $forged = (string) Str::uuid();

        $this->postSyncAs($owner, [['op' => 'PUT', 'type' => 'horse_shares', 'id' => $forged,
            'data' => ['horse_id' => $horse->id, 'grantee_user_id' => $victim->id, 'role' => 'full']]])->assertForbidden();
        $this->postSyncAs($owner, [['op' => 'PATCH', 'type' => 'horse_shares', 'id' => $share->id,
            'data' => ['grantee_user_id' => $victim->id]]])->assertForbidden();
        $this->postSyncAs($owner, [['op' => 'DELETE', 'type' => 'horse_shares', 'id' => $share->id]])->assertForbidden();

        $this->assertDatabaseMissing('horse_shares', ['id' => $forged]);
        $this->assertDatabaseHas('horse_shares', ['id' => $share->id, 'grantee_user_id' => null, 'role' => 'read_only']);
    }

    public function test_payload_cannot_claim_another_users_horse(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create();
        $horse = Horse::create(['owner_id' => $victim->id, 'name' => 'Nova', 'status' => 'active']);

        foreach (['PATCH', 'PUT'] as $op) {
            $this->postSyncAs($attacker, [['op' => $op, 'type' => 'horses', 'id' => $horse->id,
                'data' => ['owner_id' => $attacker->id, 'name' => 'Stolen']]])->assertForbidden();
        }
        $this->postSyncAs($attacker, [['op' => 'DELETE', 'type' => 'horses', 'id' => $horse->id,
            'data' => ['owner_id' => $attacker->id]]])->assertForbidden();

        $this->assertDatabaseHas('horses', ['id' => $horse->id, 'owner_id' => $victim->id, 'name' => 'Nova']);
    }

    public function test_owner_cannot_give_their_horse_away_through_sync(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $horse = Horse::create(['owner_id' => $owner->id, 'name' => 'Nova', 'status' => 'active']);

        $this->postSyncAs($owner, [['op' => 'PATCH', 'type' => 'horses', 'id' => $horse->id,
            'data' => ['owner_id' => $other->id]]])->assertForbidden();

        $this->assertDatabaseHas('horses', ['id' => $horse->id, 'owner_id' => $owner->id]);
    }

    public function test_owner_can_create_update_and_delete_their_own_rows(): void
    {
        $owner = User::factory()->create();
        $horseId = (string) Str::uuid();
        $this->postSyncAs($owner, [['op' => 'PUT', 'type' => 'horses', 'id' => $horseId,
            'data' => ['owner_id' => $owner->id, 'name' => 'Nova', 'status' => 'active']]])->assertOk();
        $this->postSyncAs($owner, [['op' => 'PUT', 'type' => 'horses', 'id' => $horseId,
            'data' => ['owner_id' => $owner->id, 'name' => 'Nova again', 'status' => 'active']]])->assertOk();
        $this->assertDatabaseHas('horses', ['id' => $horseId, 'owner_id' => $owner->id, 'name' => 'Nova again']);

        $second = Horse::create(['owner_id' => $owner->id, 'name' => 'Second', 'status' => 'active']);
        $observationId = (string) Str::uuid();
        $this->postSyncAs($owner, [['op' => 'PUT', 'type' => 'observations', 'id' => $observationId,
            'data' => ['horse_id' => $horseId, 'author_id' => $owner->id, 'date' => '2026-11-01', 'note' => 'Fine']]])->assertOk();
        // Moving a row between resources the user owns stays possible.
        $this->postSyncAs($owner, [['op' => 'PATCH', 'type' => 'observations', 'id' => $observationId,
            'data' => ['horse_id' => $second->id]]])->assertOk();
        $this->assertDatabaseHas('observations', ['id' => $observationId, 'horse_id' => $second->id]);

        $this->postSyncAs($owner, [['op' => 'DELETE', 'type' => 'observations', 'id' => $observationId]])->assertOk();
        $this->assertDatabaseMissing('observations', ['id' => $observationId]);
    }

    public function test_payload_cannot_take_over_other_users_rows(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create();
        $victimHorse = Horse::create(['owner_id' => $victim->id, 'name' => 'Victim', 'status' => 'active']);
        $attackerHorse = Horse::create(['owner_id' => $attacker->id, 'name' => 'Attacker', 'status' => 'active']);
        $item = LibraryItem::create(['slug' => (string) Str::uuid(), 'title' => 'Lesson', 'format' => 'article']);

        $observation = Observation::create(['horse_id' => $victimHorse->id, 'author_id' => $victim->id, 'date' => '2026-11-01', 'note' => 'Private']);
        $photo = ObservationPhoto::create(['observation_id' => $observation->id, 'url' => 'https://example.test/a.jpg']);
        $attackerObservation = Observation::create(['horse_id' => $attackerHorse->id, 'author_id' => $attacker->id, 'date' => '2026-11-01']);
        $scan = ScanResult::create(['user_id' => $victim->id, 'horse_id' => $victimHorse->id, 'scanned_at' => now(), 'score' => 5, 'rating' => 'Goed']);
        $attackerScan = ScanResult::create(['user_id' => $attacker->id, 'scanned_at' => now(), 'score' => 5, 'rating' => 'Goed']);
        $ingredient = ScanIngredient::create(['scan_id' => $scan->id, 'name' => 'Lijnzaad', 'tag' => 'good']);
        $bookmark = LibraryBookmark::create(['user_id' => $victim->id, 'item_id' => $item->id]);
        $progress = LibraryProgress::create(['user_id' => $victim->id, 'item_id' => $item->id, 'progress' => 0.5]);
        $preference = NotificationPreference::create(['user_id' => $victim->id, 'reminder_protocol' => true]);

        $takeovers = [
            ['observations', $observation->id, ['author_id' => $attacker->id, 'horse_id' => $attackerHorse->id, 'note' => 'Stolen']],
            ['observation_photos', $photo->id, ['observation_id' => $attackerObservation->id]],
            ['scan_results', $scan->id, ['user_id' => $attacker->id, 'horse_id' => null]],
            ['scan_ingredients', $ingredient->id, ['scan_id' => $attackerScan->id]],
            ['library_bookmarks', $bookmark->id, ['user_id' => $attacker->id]],
            ['library_progress', $progress->id, ['user_id' => $attacker->id, 'progress' => 1]],
            ['notification_preferences', $preference->id, ['user_id' => $attacker->id, 'reminder_protocol' => false]],
        ];
        foreach ($takeovers as [$table, $id, $data]) {
            foreach (['PATCH', 'PUT', 'DELETE'] as $op) {
                $this->postSyncAs($attacker, [['op' => $op, 'type' => $table, 'id' => $id, 'data' => $data]])
                    ->assertForbidden();
            }
        }

        $this->assertDatabaseHas('observations', ['id' => $observation->id, 'author_id' => $victim->id, 'horse_id' => $victimHorse->id, 'note' => 'Private']);
        $this->assertDatabaseHas('observation_photos', ['id' => $photo->id, 'observation_id' => $observation->id]);
        $this->assertDatabaseHas('scan_results', ['id' => $scan->id, 'user_id' => $victim->id, 'horse_id' => $victimHorse->id]);
        $this->assertDatabaseHas('scan_ingredients', ['id' => $ingredient->id, 'scan_id' => $scan->id]);
        $this->assertDatabaseHas('library_bookmarks', ['id' => $bookmark->id, 'user_id' => $victim->id]);
        $this->assertDatabaseHas('library_progress', ['id' => $progress->id, 'user_id' => $victim->id]);
        $this->assertDatabaseHas('notification_preferences', ['id' => $preference->id, 'user_id' => $victim->id, 'reminder_protocol' => true]);
    }

    public function test_account_sync_only_records_onboarding(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test', 'name' => 'Owner']);
        $hash = $user->password;
        $forbidden = ['password' => 'taken-over', 'email' => 'attacker@example.test', 'name' => 'Renamed',
            'admin_note' => 'cleared', 'disabled_at' => now()->toISOString(), 'remember_token' => 'token'];

        foreach (['PATCH', 'PUT'] as $op) {
            $this->postSyncAs($user, [['op' => $op, 'type' => 'users', 'id' => $user->id, 'data' => $forbidden]])
                ->assertOk();
        }
        $this->postSyncAs($user, [['op' => 'PATCH', 'type' => 'users', 'id' => $user->id,
            'data' => $forbidden + ['onboarded_at' => '2026-11-15T10:00:00.000Z']]])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame($hash, $fresh->password);
        $this->assertSame('owner@example.test', $fresh->email);
        $this->assertSame('Owner', $fresh->name);
        $this->assertNull($fresh->admin_note);
        $this->assertNull($fresh->disabled_at);
        $this->assertTrue($fresh->onboarded_at->equalTo('2026-11-15 10:00:00'));

        $this->postSyncAs($user, [['op' => 'PUT', 'type' => 'users', 'id' => $user->id,
            'data' => ['onboarded_at' => 'not a date']]])->assertUnprocessable()->assertJsonValidationErrors('onboarded_at');
        $this->postSyncAs($user, [['op' => 'PATCH', 'type' => 'users', 'id' => User::factory()->create()->id,
            'data' => ['onboarded_at' => now()->toISOString()]]])->assertForbidden();
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
        if (! app(\App\Support\SubscriptionAccess::class)->active($user->id)) $this->subscribe($user);
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
