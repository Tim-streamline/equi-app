<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\AdminUser;
use App\Models\FeedLabelAttachment;
use App\Models\FeedScanSubmission;
use App\Models\User;
use App\Support\FeedCompositionOcr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeedScanPhotoColumnsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->owner = User::factory()->create();
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($this->owner->id)
        {
            public function __construct(private string $id) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->id);

                return $next($request);
            }
        });
    }

    private function submission(): FeedScanSubmission
    {
        return FeedScanSubmission::create(['user_id' => $this->owner->id, 'client_id' => Str::uuid(), 'status' => 'awaiting_composition', 'workflow_version' => 2]);
    }

    private function legacyPhoto(FeedScanSubmission $s, string $purpose, string $contents): FeedLabelAttachment
    {
        $path = 'feed-labels/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $contents);

        return FeedLabelAttachment::create(['submission_id' => $s->id, 'purpose' => $purpose, 'disk' => 'local', 'path' => $path,
            'original_name' => $purpose.'.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => strlen($contents), 'checksum' => hash('sha256', $contents)]);
    }

    public function test_migration_preserves_primary_photos_matching_ocr_and_results_and_ignores_extras(): void
    {
        $s = $this->submission();
        $ingredients = $this->legacyPhoto($s, 'composition', 'ingredients bytes');
        $analysis = $this->legacyPhoto($s, 'analysis', 'nutrition bytes');
        $front = $this->legacyPhoto($s, 'packaging', 'product bytes');
        $extra = $this->legacyPhoto($s, 'packaging', 'ignored extra bytes');
        $old = $this->legacyPhoto($s, 'composition', 'replaced ingredients bytes');
        $ids = ['composition' => $ingredients->id, 'analysis' => $analysis->id, 'front' => $front->id, 'extra-0' => $extra->id];
        $recognition = ['decision' => 'uncertain', 'photo_id' => $ingredients->id,
            'ocr' => ['text' => "Samenstelling:\nLuzerne, lijnzaad.", 'confidence' => 94.578, 'words' => 3, 'low_confidence_words' => 0, 'engine' => 'tesseract-local-1']];
        DB::table('feed_scan_submissions')->where('id', $s->id)->update(['status' => 'completed', 'photo_ids' => json_encode($ids),
            'recognition' => json_encode($recognition), 'public_result' => json_encode(['name' => 'Historical result']), 'extraction' => json_encode(['ingredients' => []])]);
        $before = DB::table('feed_scan_submissions')->find($s->id);
        $migration = require database_path('migrations/2026_10_29_000001_store_feed_scan_photos_in_submission_columns.php');
        $migration->backfill();
        $s->refresh();
        $this->assertSame(array_intersect_key($ids, FeedScanSubmission::PHOTO_SLOTS), $s->photo_ids);
        $this->assertSame($ingredients->path, $s->ingredients_photo_path);
        $this->assertSame($analysis->path, $s->nutrition_photo_path);
        $this->assertSame($front->path, $s->product_photo_path);
        $this->assertSame($recognition['ocr']['text'], $s->ingredients_photo_ocr_text);
        $this->assertSame(94.578, $s->ingredients_photo_ocr_confidence);
        $this->assertSame('completed', $s->ingredients_photo_ocr_status);
        $this->assertNull($s->ingredients_photo_ocr_processed_at); // The old schema did not record this timestamp.
        $this->assertSame('uncertain', $s->recognition_decision);
        $this->assertSame('completed', $s->status);
        $this->assertSame(['name' => 'Historical result'], $s->public_result);
        $this->assertSame(['ingredients' => []], $s->extraction);
        $this->assertSame($before->updated_at, DB::table('feed_scan_submissions')->find($s->id)->updated_at);
        $this->assertSame($before->photo_ids, $s->getRawOriginal('photo_ids'));
        $this->assertSame($before->recognition, $s->getRawOriginal('recognition'));
        foreach ([$ingredients, $analysis, $front, $extra, $old] as $photo) {
            Storage::disk('local')->assertExists($photo->path);
            $this->assertSame($photo->checksum, hash('sha256', Storage::disk('local')->get($photo->path)));
        }
        $this->assertSame(5, FeedLabelAttachment::count());
        $this->assertSame(5, $s->photo_upload_count);
        $after = $s->getAttributes();
        $migration->backfill();
        $this->assertSame($after, $s->fresh()->getAttributes());
    }

    public function test_migration_does_not_attach_old_ocr_to_replacement_or_foreign_photos(): void
    {
        $s = $this->submission();
        $old = $this->legacyPhoto($s, 'composition', 'old');
        $new = $this->legacyPhoto($s, 'composition', 'new');
        $other = $this->submission();
        $foreign = $this->legacyPhoto($other, 'analysis', 'foreign');
        DB::table('feed_scan_submissions')->where('id', $s->id)->update(['photo_ids' => json_encode(['composition' => $new->id, 'analysis' => $foreign->id]),
            'recognition' => json_encode(['decision' => 'candidate', 'photo_id' => $old->id, 'ocr' => ['text' => 'Old label', 'confidence' => 99]])]);
        $migration = require database_path('migrations/2026_10_29_000001_store_feed_scan_photos_in_submission_columns.php');
        $migration->backfill();
        $s->refresh();
        $this->assertSame(['composition' => $new->id], $s->photo_ids);
        $this->assertNull($s->ingredients_photo_ocr_text);
        $this->assertNull($s->recognition);
        $this->assertNull($s->nutrition_photo_path);
    }

    public function test_uploads_use_columns_and_replacement_clears_only_its_ocr_and_dependent_results(): void
    {
        $this->mock(FeedCompositionOcr::class, fn ($mock) => $mock->shouldReceive('read')->twice()->andReturn(['text' => 'New photo text', 'confidence' => 99, 'engine' => 'test']));
        $s = $this->submission();
        $url = "/api/feed-scanner/submissions/{$s->id}/photos";
        $this->post($url, ['slot' => 'composition', 'file' => UploadedFile::fake()->image('first.jpg')])->assertOk()
            ->assertJsonMissingPath('ingredients_photo_path');
        $s->refresh();
        $originalId = $s->ingredients_photo_id;
        $s->setPhotoOcr('composition', ['text' => 'Old label', 'confidence' => 99, 'engine' => 'test']);
        $s->setPhotoOcr('analysis', ['text' => 'Nutrition evidence', 'confidence' => 98, 'engine' => 'test']);
        $s->setRecognitionData(['decision' => 'uncertain', 'photo_id' => $originalId]);
        $s->fill(['status' => 'failed', 'extraction' => ['name' => 'Old extraction'], 'public_result' => ['name' => 'Old result']])->save();
        $this->post($url, ['slot' => 'composition', 'file' => UploadedFile::fake()->image('replacement.jpg', 120, 80)])
            ->assertOk()->assertJsonPath('status', 'awaiting_composition')->assertJsonPath('recognition', null);
        $s->refresh();
        $this->assertNotSame($originalId, $s->ingredients_photo_id);
        $this->assertSame('New photo text', $s->ingredients_photo_ocr_text);
        $this->assertSame('completed', $s->ingredients_photo_ocr_status);
        $this->assertNull($s->extraction);
        $this->assertNull($s->public_result);
        $this->assertSame('Nutrition evidence', $s->nutrition_photo_ocr_text);
        $this->assertSame(0, FeedLabelAttachment::count());
        $this->assertSame([], json_decode($s->getRawOriginal('photo_ids'), true));
        $this->assertNull($s->getRawOriginal('recognition'));
        $this->post($url, ['slot' => 'extra-0', 'file' => UploadedFile::fake()->image('extra.jpg')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->get($url.'/'.$originalId)->assertNotFound();
    }

    public function test_late_ocr_cannot_be_saved_onto_a_replacement_photo(): void
    {
        $s = $this->submission();
        $this->post("/api/feed-scanner/submissions/{$s->id}/photos", ['slot' => 'composition', 'file' => UploadedFile::fake()->image('label.jpg')])->assertOk();
        $s->refresh();
        $s->setPhotoOcr('composition', null);
        $s->save();
        $replacementId = (string) Str::uuid();
        $this->mock(FeedCompositionOcr::class, fn ($mock) => $mock->shouldReceive('read')->once()->andReturnUsing(function () use ($s, $replacementId) {
            // Simulate an upload after an expired processing lease, keeping the token to exercise photo identity too.
            FeedScanSubmission::whereKey($s->id)->update(['ingredients_photo_id' => $replacementId,
                'ingredients_photo_checksum' => hash('sha256', 'replacement'), 'status' => 'awaiting_composition']);

            return ['text' => 'Old OCR', 'confidence' => 99, 'words' => 20, 'low_confidence_words' => 0, 'engine' => 'test'];
        }));
        $this->postJson("/api/feed-scanner/submissions/{$s->id}/check")->assertOk()->assertJsonPath('status', 'awaiting_composition');
        $this->assertNull($s->fresh()->ingredients_photo_ocr_text);
        $this->assertNull($s->fresh()->recognition_decision);
    }

    public function test_admin_can_download_current_photo_but_not_replaced_photo(): void
    {
        $s = $this->submission();
        $url = "/api/feed-scanner/submissions/{$s->id}/photos";
        $this->post($url, ['slot' => 'composition', 'file' => UploadedFile::fake()->image('label.jpg')])->assertOk();
        $photo = $s->fresh()->photo('composition');
        $adminUrl = "/admin/feed-scanner/submissions/{$s->id}/photos/{$photo->id}";
        $this->get($adminUrl)->assertRedirect();
        $admin = AdminUser::create(['name' => 'Reviewer', 'email' => 'ocr-reviewer@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $this->actingAs($admin, 'admin')->get($adminUrl)->assertOk();
        $this->post($url, ['slot' => 'composition', 'file' => UploadedFile::fake()->image('new.jpg', 120, 80)])->assertOk();
        $this->get($adminUrl)->assertNotFound();
    }
}
