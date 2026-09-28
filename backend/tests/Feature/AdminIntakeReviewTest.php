<?php

namespace Tests\Feature;

use App\Models\{AdminUser, Horse, IntakeBooking, IntakeField, IntakeQuestionnaire, IntakeSection, User};
use App\Support\IntakeReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Schema, Storage};
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminIntakeReviewTest extends TestCase
{
    use RefreshDatabase;
    private IntakeBooking $booking;
    private IntakeSection $section;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $this->booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id, 'intake_status' => 'submitted', 'submitted_at' => now()]);
        $q = IntakeQuestionnaire::create(['slug' => 'protocol-intake', 'name' => 'Current questionnaire', 'active' => true, 'none_options' => ['geen', 'Geen van onderstaande']]);
        $this->section = $q->sections()->create(['key' => 'paard', 'title' => 'Current horse section', 'order' => 1, 'active' => true]);
        $this->actingAs(AdminUser::create(['name' => 'Reviewer', 'email' => 'review@example.test', 'password' => 'password', 'role' => 'owner', 'active' => true]), 'admin');
    }

    private function field(string $key, string $type = 'text', array $extra = [], mixed $value = null): IntakeField
    {
        $field = $this->section->fields()->create(array_merge(['key' => $key, 'label' => ucfirst($key), 'order' => $this->section->fields()->count(), 'type' => $type, 'active' => true], $extra));
        if ($value !== null) $this->answer($key, $value);
        return $field;
    }
    private function answer(string $key, mixed $value): void
    {
        $this->booking->answers()->updateOrCreate(['section_id' => 'paard', 'field_id' => $key], ['value' => json_encode($value)]);
        $this->booking->unsetRelation('answers');
    }

    public function test_review_and_print_use_current_schema_without_invented_protocol_rules(): void
    {
        $this->field('gewicht', 'number', ['unit' => 'kg'], 540);
        $this->field('new-question', value: 'New admin question');
        $this->field('removed', extra: ['active' => false]);
        $this->get('/admin/bookings/'.$this->booking->id)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Bookings/Show')->where('booking.id', $this->booking->id)->has('review.sections', 1)
            ->where('review.sections.0.title', 'Current horse section')->has('review.sections.0.rows', 2)
            ->where('review.counts.protocol', 0)->where('review.counts.all', 2)->where('printMode', false));
        $this->get('/admin/bookings/'.$this->booking->id.'?print=1')->assertInertia(fn (Assert $p) => $p->where('printMode', true)->has('review.sections.0.rows', 2));
    }

    public function test_visibility_flags_and_protocol_hits_use_question_metadata(): void
    {
        $this->field('heading', 'sectionhead');
        $this->field('choice', 'multi', ['flag_if' => ['risk'], 'critical_if' => ['danger'], 'protocol_if' => ['risk' => 'Review choice', 'veiligheid' => 'Pause protocol']], ['neutral', 'risk', 'danger']);
        $this->field('hidden', extra: ['show_if' => ['choice' => 'missing']], value: 'stale');
        $this->field('visible', extra: ['show_if' => ['choice' => 'multi-checked']], value: 'yes');
        $this->field('none', 'multi', ['flag_if' => 'any'], ['Geen van onderstaande']);
        $this->field('none-child', extra: ['show_if' => ['none' => 'any-checked']]);
        $this->field('negative', extra: ['flag_if' => 'non-empty'], value: 'Nee, niets');
        $this->field('positive', extra: ['flag_if' => 'non-empty', 'protocol_if' => ['non-empty' => 'Review text']], value: 'Something');
        $this->field('missing');
        $this->field('zero', 'number', value: 0);
        $this->field('repeater', 'repeater', ['repeater_sub' => [['id' => 'name', 'label' => 'Name']]], [['name' => '']]);
        $review = app(IntakeReview::class)->forBooking($this->booking);
        $rows = collect($review['sections'][0]['rows'])->keyBy('key');
        $this->assertFalse($rows->has('hidden'));
        $this->assertFalse($rows->has('none-child'));
        $this->assertTrue($rows['choice']['critical']);
        $this->assertSame(['risk', 'danger'], $rows['choice']['flagged_options']);
        $this->assertFalse($rows['none']['flagged']);
        $this->assertFalse($rows['negative']['flagged']);
        $this->assertFalse($rows['zero']['empty']);
        $this->assertTrue($rows['repeater']['empty']);
        $this->assertSame(['all' => 8, 'flags' => 2, 'protocol' => 2, 'empty' => 2], $review['counts']);
        $this->assertTrue($review['blocked']);
        $this->assertSame(['Review choice', 'Pause protocol'], $rows['choice']['protocol']);
    }

    public function test_cross_section_conditions_and_changed_rules_are_live(): void
    {
        $this->field('geslacht', 'radio', value: 'merrie');
        $section = $this->section->questionnaire->sections()->create(['key' => 'health', 'title' => 'Health', 'order' => 2, 'active' => true]);
        $field = $section->fields()->create(['key' => 'pregnant', 'label' => 'Pregnant?', 'type' => 'radio', 'active' => true, 'show_if' => ['paard.geslacht' => 'merrie']]);
        $this->assertCount(1, app(IntakeReview::class)->forBooking($this->booking)['sections'][1]['rows']);
        $field->update(['show_if' => ['paard.geslacht' => 'hengst']]);
        $this->assertCount(0, app(IntakeReview::class)->forBooking($this->booking)['sections'][1]['rows']);
    }

    public function test_notes_and_selections_survive_reload_and_invalid_triggers_are_rejected(): void
    {
        $this->field('risk', extra: ['protocol_if' => ['non-empty' => 'Review this']], value: 'Yes');
        $url = '/admin/bookings/'.$this->booking->id.'/review';
        $this->patchJson($url, ['notes' => 'Ask owner', 'accepted_triggers' => ['paard.risk']])->assertOk();
        $this->get('/admin/bookings/'.$this->booking->id)->assertInertia(fn (Assert $p) => $p->where('review.notes', 'Ask owner')->where('review.accepted', ['paard.risk']));
        $this->patchJson($url, ['accepted_triggers' => ['invented']])->assertUnprocessable();
        $this->patchJson($url, ['notes' => 'Only notes changed'])->assertOk();
        $this->assertSame(['paard.risk'], $this->booking->fresh()->accepted_triggers);
        $this->patchJson($url, ['accepted_triggers' => []])->assertOk();
        $this->assertSame([], $this->booking->fresh()->accepted_triggers);
    }

    public function test_real_attachment_upload_download_and_owner_isolation(): void
    {
        Storage::fake('local');
        $this->field('photo', 'photo');
        $this->field('document', 'file');
        $this->asApiUser($this->booking->user);
        $payload = ['horse_id' => $this->booking->horse_id, 'section' => 'paard', 'field' => 'photo'];
        $uploaded = $this->postJson('/api/intakes/'.$this->booking->id.'/attachments', $payload + ['file' => UploadedFile::fake()->image('horse.jpg')])->assertCreated();
        $reference = $uploaded->json('value');
        $id = explode(':', $reference)[1];
        $this->answer('photo', [$reference]);
        $this->assertSame('horse.jpg', app(IntakeReview::class)->forBooking($this->booking)['sections'][0]['rows'][0]['attachments'][0]['name']);
        $this->get('/api/intake-media/'.$id)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/admin/intake-media/'.$id.'?download=1')->assertOk()->assertDownload('horse.jpg');
        $this->asApiUser(User::factory()->create());
        $this->get('/api/intake-media/'.$id)->assertForbidden();
        $this->postJson('/api/intakes/'.$this->booking->id.'/attachments', $payload + ['file' => UploadedFile::fake()->image('other.jpg')])->assertForbidden();
        $this->asApiUser($this->booking->user);
        $this->postJson('/api/intakes/'.$this->booking->id.'/attachments', $payload + ['file' => UploadedFile::fake()->create('script.html', 1, 'text/html')])->assertUnprocessable();
        $pdf = UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $this->postJson('/api/intakes/'.$this->booking->id.'/attachments', array_merge($payload, ['field' => 'document', 'file' => $pdf]))->assertCreated();
    }

    public function test_upload_before_sync_keeps_same_id_and_review_fields_are_admin_only(): void
    {
        Storage::fake('local');
        $this->field('photo', 'photo');
        $this->asApiUser($this->booking->user);
        $id = (string) Str::uuid();
        $this->postJson('/api/intakes/'.$id.'/attachments', ['horse_id' => $this->booking->horse_id, 'section' => 'paard', 'field' => 'photo', 'file' => UploadedFile::fake()->image('horse.jpg')])->assertCreated();
        $this->postJson('/api/sync/upload', ['operations' => [['op' => 'PUT', 'type' => 'intake_bookings', 'id' => $id, 'data' => ['user_id' => $this->booking->user_id, 'horse_id' => $this->booking->horse_id, 'intake_status' => 'submitted', 'review_notes' => 'forged', 'accepted_triggers' => ['forged']]]]])->assertOk();
        $this->assertSame(1, IntakeBooking::whereKey($id)->count());
        $this->assertNull(IntakeBooking::find($id)->review_notes);
        $this->assertSame([], IntakeBooking::find($id)->accepted_triggers);
    }

    public function test_review_requires_admin_authentication(): void
    {
        $this->app['auth']->guard('admin')->logout();
        $this->get('/admin/bookings/'.$this->booking->id)->assertRedirect('/admin/login');
        $this->patchJson('/admin/bookings/'.$this->booking->id.'/review', ['notes' => 'x'])->assertUnauthorized();
    }

    public function test_migration_preserves_matching_booking_and_response_answers(): void
    {
        $migration = require database_path('migrations/2026_09_24_180000_unify_intake_bookings.php');
        $migration->down();
        DB::table('intake_responses')->delete();
        $response = (string) Str::uuid();
        DB::table('intake_responses')->insert(['id' => $response, 'user_id' => $this->booking->user_id, 'horse_id' => $this->booking->horse_id, 'status' => 'submitted', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $answer = (string) Str::uuid();
        DB::table('intake_answers')->insert(['id' => $answer, 'response_id' => $response, 'section_id' => 'paard', 'field_id' => 'name', 'value' => '"Nova"']);
        $migration->up();
        $this->assertFalse(Schema::hasTable('intake_responses'));
        $this->assertDatabaseHas('intake_answers', ['id' => $answer, 'response_id' => $this->booking->id, 'value' => '"Nova"']);
        $this->assertSame('submitted', $this->booking->fresh()->intake_status);
        $this->assertSame(1, IntakeBooking::count());
    }

    private function asApiUser(User $user): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $path = storage_path('framework/testing/intake-review-public.pem');
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
        file_put_contents($path, openssl_pkey_get_details($key)['key']);
        config(['powersync.public_key_path' => $path, 'powersync.audience' => 'equinova']);
        $token = \Firebase\JWT\JWT::encode(['sub' => $user->id, 'aud' => 'equinova', 'iat' => time(), 'exp' => time()+3600], $private, 'RS256');
        $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
