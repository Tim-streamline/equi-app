<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
}
