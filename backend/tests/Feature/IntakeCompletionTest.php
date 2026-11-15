<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\IntakeProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntakeCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): IntakeBooking
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $q = IntakeQuestionnaire::create(['slug' => 'protocol-intake', 'name' => 'Test', 'active' => true]);
        $s = $q->sections()->create(['key' => 'paard', 'title' => 'Paard', 'active' => true]);
        $s->fields()->create(['key' => 'naam', 'label' => 'Naam', 'type' => 'text', 'optional' => false, 'active' => true]);
        $s->fields()->create(['key' => 'extra', 'label' => 'Extra', 'type' => 'text', 'optional' => true, 'active' => true]);
        $s->fields()->create(['key' => 'foto', 'label' => 'Foto', 'type' => 'photo', 'optional' => true, 'active' => true]);
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($user->id)
        {
            public function __construct(private string $id) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->id);

                return $next($request);
            }
        });

        return $booking;
    }

    public function test_submission_requires_complete_answers_and_uploaded_files_and_is_idempotent(): void
    {
        Storage::fake('local');
        $b = $this->fixture();
        $url = '/api/intakes/'.$b->id.'/submit';
        $this->postJson($url)->assertUnprocessable();
        $this->assertNull($b->fresh()->completed_at);
        $b->answers()->create(['section_id' => 'paard', 'field_id' => 'naam', 'value' => '"Nova"']);
        $id = (string) Str::uuid();
        $b->answers()->create(['section_id' => 'paard', 'field_id' => 'foto', 'value' => json_encode(['attachment:'.$id.':foto.jpg'])]);
        $this->postJson($url)->assertUnprocessable();
        $upload = ['attachment_id' => $id, 'horse_id' => $b->horse_id, 'section' => 'paard', 'field' => 'foto', 'file' => UploadedFile::fake()->image('foto.jpg')];
        $this->postJson('/api/intakes/'.$b->id.'/attachments', $upload)->assertCreated();
        $this->freezeTime();
        $stamp = $this->postJson($url)->assertOk()->json('completed_at');
        $this->assertTrue($b->fresh()->completed_at->equalTo(now()->startOfSecond()));
        $this->assertTrue($b->fresh()->submitted_at->equalTo($b->fresh()->completed_at));
        $this->assertSame('pending', $b->fresh()->submission_email_status);
        $b->update(['status' => 'done']);
        $this->travel(1)->day();
        $this->postJson($url)->assertOk()->assertJsonPath('completed_at', $stamp);
        $this->assertSame('done', $b->fresh()->status);
        // An already successful upload retry is safe, but new uploads are locked.
        $this->postJson('/api/intakes/'.$b->id.'/attachments', $upload)->assertOk();
        $upload['attachment_id'] = (string) Str::uuid();
        $this->postJson('/api/intakes/'.$b->id.'/attachments', $upload)->assertStatus(409);
    }

    public function test_completed_intake_rejects_mutation_from_stale_devices_without_blocking_sync(): void
    {
        $b = $this->fixture();
        $answer = $b->answers()->create(['section_id' => 'paard', 'field_id' => 'naam', 'value' => '"Nova"']);
        $stamp = $this->postJson('/api/intakes/'.$b->id.'/submit')->assertOk()->json('completed_at');
        foreach (['PUT', 'PATCH', 'DELETE'] as $operation) {
            $this->postJson('/api/sync/upload', ['operations' => [
                ['op' => $operation, 'type' => 'intake_answers', 'id' => $answer->id, 'data' => ['response_id' => $b->id, 'section_id' => 'paard', 'field_id' => 'naam', 'value' => '"Changed"']],
                ['op' => $operation, 'type' => 'intake_bookings', 'id' => $b->id, 'data' => ['intake_status' => 'draft', 'submitted_at' => null, 'completed_at' => null]],
            ]])->assertOk()->assertJsonPath('applied', 0)->assertJsonPath('skipped', 2);
        }
        $this->assertSame('"Nova"', $answer->fresh()->value);
        $this->assertSame($stamp, $b->fresh()->completed_at->toISOString());
        $other = User::factory()->create();
        $foreign = IntakeBooking::create(['user_id' => $other->id, 'horse_id' => Horse::create(['owner_id' => $other->id, 'name' => 'Other'])->id]);
        $this->postJson('/api/intakes/'.$foreign->id.'/submit')->assertForbidden();
        $this->postJson('/api/sync/upload', ['operations' => [['op' => 'PATCH', 'type' => 'intake_bookings', 'id' => $foreign->id, 'data' => ['completed_at' => $stamp]]]])->assertForbidden();
    }

    public function test_cancelled_intake_refuses_new_uploads_but_still_acknowledges_a_lost_response(): void
    {
        Storage::fake('local');
        $b = $this->fixture();
        $upload = ['attachment_id' => (string) Str::uuid(), 'horse_id' => $b->horse_id, 'section' => 'paard', 'field' => 'foto', 'file' => UploadedFile::fake()->image('foto.jpg')];
        $value = $this->postJson('/api/intakes/'.$b->id.'/attachments', $upload)->assertCreated()->json('value');
        $b->update(['status' => 'cancelled']);
        $this->postJson('/api/intakes/'.$b->id.'/attachments', $upload)->assertOk()->assertJsonPath('value', $value);
        $upload['attachment_id'] = (string) Str::uuid();
        $this->postJson('/api/intakes/'.$b->id.'/attachments', $upload)->assertStatus(409);
        $this->assertDatabaseMissing('intake_attachments', ['id' => $upload['attachment_id']]);
        $this->assertCount(1, Storage::disk('local')->allFiles('intake/'.$b->id));
    }

    public function test_cancelled_intake_answers_are_acknowledged_and_cannot_be_routed_through_another_intake(): void
    {
        $cancelled = $this->fixture();
        $active = IntakeBooking::create(['user_id' => $cancelled->user_id, 'horse_id' => $cancelled->horse_id]);
        $answer = $cancelled->answers()->create(['section_id' => 'paard', 'field_id' => 'naam', 'value' => '"Nova"']);
        $cancelled->update(['status' => 'cancelled']);
        $newId = (string) Str::uuid();
        $validId = (string) Str::uuid();
        $data = ['response_id' => $active->id, 'section_id' => 'paard', 'field_id' => 'naam', 'value' => '"Changed"'];

        $this->postJson('/api/sync/upload', ['operations' => [
            ['op' => 'PATCH', 'type' => 'intake_answers', 'id' => $answer->id, 'data' => $data],
            ['op' => 'PUT', 'type' => 'intake_answers', 'id' => $answer->id, 'data' => $data],
            ['op' => 'DELETE', 'type' => 'intake_answers', 'id' => $answer->id, 'data' => ['response_id' => $active->id]],
            ['op' => 'PUT', 'type' => 'intake_answers', 'id' => $newId, 'data' => ['response_id' => $cancelled->id] + $data],
            ['op' => 'PUT', 'type' => 'intake_answers', 'id' => $validId, 'data' => $data],
        ]])->assertOk()->assertJsonPath('applied', 1)->assertJsonPath('skipped', 4);

        $this->assertDatabaseHas('intake_answers', ['id' => $answer->id, 'response_id' => $cancelled->id, 'value' => '"Nova"']);
        $this->assertDatabaseMissing('intake_answers', ['id' => $newId]);
        $this->assertDatabaseHas('intake_answers', ['id' => $validId, 'response_id' => $active->id, 'value' => '"Changed"']);
    }

    public function test_cancelled_intake_cannot_be_reopened_from_a_device_before_answers_change(): void
    {
        $b = $this->fixture();
        $answer = $b->answers()->create(['section_id' => 'paard', 'field_id' => 'naam', 'value' => '"Nova"']);
        $b->update(['status' => 'cancelled']);

        $this->postJson('/api/sync/upload', ['operations' => [
            ['op' => 'PATCH', 'type' => 'intake_bookings', 'id' => $b->id, 'data' => ['status' => 'pending']],
            ['op' => 'PATCH', 'type' => 'intake_answers', 'id' => $answer->id, 'data' => ['value' => '"Changed"']],
            ['op' => 'DELETE', 'type' => 'intake_bookings', 'id' => $b->id, 'data' => null],
        ]])->assertOk()->assertJsonPath('applied', 0)->assertJsonPath('skipped', 3);

        $this->assertDatabaseHas('intake_bookings', ['id' => $b->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('intake_answers', ['id' => $answer->id, 'value' => '"Nova"']);
    }

    public function test_submitted_intake_answers_cannot_be_rerouted_into_an_open_intake(): void
    {
        $submitted = $this->fixture();
        $open = IntakeBooking::create(['user_id' => $submitted->user_id, 'horse_id' => $submitted->horse_id]);
        $answer = $submitted->answers()->create(['section_id' => 'paard', 'field_id' => 'naam', 'value' => '"Nova"']);
        $submitted->forceFill(['submitted_at' => now(), 'completed_at' => now()])->save();

        $this->postJson('/api/sync/upload', ['operations' => [
            ['op' => 'PATCH', 'type' => 'intake_answers', 'id' => $answer->id, 'data' => ['response_id' => $open->id, 'value' => '"Changed"']],
        ]])->assertOk()->assertJsonPath('applied', 0)->assertJsonPath('skipped', 1);

        $this->assertDatabaseHas('intake_answers', ['id' => $answer->id, 'response_id' => $submitted->id, 'value' => '"Nova"']);
    }

    public function test_legacy_sync_submission_uses_server_validation_and_time(): void
    {
        $b = $this->fixture();
        $op = ['op' => 'PATCH', 'type' => 'intake_bookings', 'id' => $b->id, 'data' => ['intake_status' => 'submitted', 'completed_at' => '2001-01-01']];
        $this->postJson('/api/sync/upload', ['operations' => [$op]])->assertUnprocessable();
        $this->assertNull($b->fresh()->completed_at);
        $this->freezeTime();
        $answer = ['op' => 'PUT', 'type' => 'intake_answers', 'id' => (string) Str::uuid(), 'data' => ['response_id' => $b->id, 'section_id' => 'paard', 'field_id' => 'naam', 'value' => '"Nova"']];
        $this->postJson('/api/sync/upload', ['operations' => [$answer, $op]])->assertOk();
        $this->assertTrue($b->fresh()->completed_at->equalTo(now()->startOfSecond()));
    }

    public static function measuredMethods(): array
    {
        return [
            ['gewogen op weegbrug'],
            ['gemeten met gewichts-lint'],
            ['rapport dierenarts'],
        ];
    }

    #[DataProvider('measuredMethods')]
    public function test_measured_weight_requires_a_date_for_progress_and_both_submission_paths(string $method): void
    {
        $b = $this->fixture();
        $section = IntakeQuestionnaire::where('slug', 'protocol-intake')->first()->sections()->first();
        $field = $section->fields()->create(['key' => 'gewicht-methode', 'label' => 'Hoe is dit gewicht bepaald?',
            'type' => 'radio', 'options' => ['geschat op zicht', ...array_column(self::measuredMethods(), 0)], 'active' => true]);
        (require database_path('migrations/2026_10_20_000002_add_weight_measurement_date_question.php'))->up();
        $b->answers()->create(['section_id' => 'paard', 'field_id' => 'naam', 'value' => '"Nova"']);
        $methodAnswer = $b->answers()->create(['section_id' => 'paard', 'field_id' => 'gewicht-methode', 'value' => json_encode($method)]);
        $date = $b->answers()->create(['section_id' => 'paard', 'field_id' => 'gewicht-methode-wanneer', 'value' => 'null']);
        $op = ['op' => 'PATCH', 'type' => 'intake_bookings', 'id' => $b->id, 'data' => ['intake_status' => 'submitted']];
        foreach ([null, '', " \n ", 42, [], ['date' => 'mei 2026']] as $invalid) {
            $date->update(['value' => json_encode($invalid)]);
            $progress = app(IntakeProgress::class)->forBooking($b->fresh());
            $this->assertSame(3, $progress['total']);
            $this->assertSame(2, $progress['answered']);
            $this->assertSame([['section' => 'paard', 'field' => 'gewicht-methode-wanneer']], $progress['missing']);
            $this->postJson('/api/intakes/'.$b->id.'/submit')->assertUnprocessable();
            $this->postJson('/api/sync/upload', ['operations' => [$op]])->assertUnprocessable();
            $this->assertNull($b->fresh()->completed_at);
        }
        // A selected measured method still needs its date if the method question becomes optional.
        $field->update(['optional' => true]);
        $this->assertCount(1, app(IntakeProgress::class)->forBooking($b->fresh())['missing']);
        $methodAnswer->update(['value' => '"geschat op zicht"']);
        $this->assertSame([], app(IntakeProgress::class)->forBooking($b->fresh())['missing']);
        $methodAnswer->update(['value' => json_encode($method)]);
        $field->update(['optional' => false]);
        $date->update(['value' => '"mei 2026"']);
        $this->assertSame('ready', app(IntakeProgress::class)->forBooking($b->fresh())['stage']);
        $this->postJson('/api/sync/upload', ['operations' => [$op]])->assertOk();
        $stamp = $b->fresh()->completed_at;
        $this->assertNotNull($stamp);
        // Previously completed intakes remain idempotent even if a historical date is missing.
        $date->delete();
        $this->postJson('/api/intakes/'.$b->id.'/submit')->assertOk();
        $this->assertTrue($b->fresh()->completed_at->equalTo($stamp));
    }
}
