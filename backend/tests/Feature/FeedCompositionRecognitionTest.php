<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\AdminUser;
use App\Models\FeedAssessment;
use App\Models\FeedLabelAttachment;
use App\Models\FeedRuleVersion;
use App\Models\FeedScanSubmission;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\ScanResult;
use App\Models\User;
use App\Support\FeedAssessments;
use App\Support\FeedCatalog;
use App\Support\FeedCompositionOcr;
use App\Support\FeedLabelReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeedCompositionRecognitionTest extends TestCase
{
    use RefreshDatabase;

    private const RECIPE = 'Timotheegras, luzerne, lijnzaad 7,88%, calciumcarbonaat, zout.';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->owner = User::factory()->create();
        $this->owner($this->owner);
    }

    private function owner(User $user): void
    {
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($user->id)
        {
            public function __construct(private string $id) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->id);

                return $next($request);
            }
        });
    }

    private function product(string $name = 'Balansvoer'): Product
    {
        $product = Product::create(['brand' => 'Testmerk', 'name' => $name]);
        $version = FeedCatalog::productDraft($product, ['brand' => 'Testmerk', 'name' => $name, 'barcode' => null, 'category' => 'complementary', 'composition_text' => self::RECIPE,
            'recognition_enabled' => true, 'ingredients' => [], 'nutrients' => [['code' => 'sugar', 'value' => '6', 'unit' => '%', 'basis' => 'product', 'qualifier' => 'exact'], ['code' => 'starch', 'value' => '14', 'unit' => '%', 'basis' => 'product', 'qualifier' => 'exact']]], null);
        $version->update(['status' => 'approved']);
        $product->forceFill(['catalog_status' => 'published', 'published_version_id' => $version->id, 'needs_review' => false])->save();
        $profile = DB::table('feed_assessment_profiles')->where('code', 'standard')->first();
        $rule = FeedRuleVersion::where('profile_id', $profile->id)->latest('number')->firstOrFail();
        if ($rule->status !== 'published') {
            $rule->update(['status' => 'published']);
        }
        DB::table('feed_assessment_profiles')->where('id', $profile->id)->update(['active_rule_version_id' => $rule->id]);
        app(FeedAssessments::class)->calculate($product, $version);

        return $product->fresh();
    }

    private function ocr(?string $text = null, float $confidence = 99): void
    {
        $this->mock(FeedCompositionOcr::class, fn ($mock) => $mock->shouldReceive('read')->once()->andReturn([
            'text' => $text ?? "Testmerk Balansvoer\nSamenstelling:\n".self::RECIPE."\nAnalytische bestanddelen\nRuw eiwit 11 procent",
            'confidence' => $confidence, 'words' => 20, 'low_confidence_words' => $confidence < 90 ? 5 : 0, 'engine' => 'test-ocr']));
    }

    private function submission(?Product $reference = null): string
    {
        $data = ['client_id' => (string) Str::uuid(), 'name' => 'Eigen etiket'];
        if ($reference) {
            $data['reference_product_id'] = $reference->id;
        }
        $id = $this->postJson('/api/feed-scanner/submissions', $data)->assertOk()->assertJsonPath('status', 'awaiting_composition')->assertJsonPath('photo_ids', [])->json('id');
        $this->upload($id, 'composition');

        return $id;
    }

    private function upload(string $id, string $slot): void
    {
        $this->post('/api/feed-scanner/submissions/'.$id.'/photos', ['slot' => $slot, 'file' => UploadedFile::fake()->image('etiket.jpg')], ['Accept' => 'application/json'])->assertOk();
    }

    private function pendingSubmission(Product $product): string
    {
        $this->ocr('', 0);
        $id = $this->submission($product);
        $this->postJson("/api/feed-scanner/submissions/$id/check")->assertJsonPath('status', 'needs_photos');
        $this->upload($id, 'analysis');
        $this->upload($id, 'front');

        return $id;
    }

    private function extractedLabel(): array
    {
        return ['brand' => 'Testmerk', 'name' => 'Balansvoer', 'barcode' => null,
            'category' => 'complementary', 'category_confidence' => 'high',
            'composition_text' => self::RECIPE, 'composition_confidence' => 'high',
            'ingredients' => [], 'nutrients' => [], 'unreadable_fields' => []];
    }

    public function test_overlapping_submissions_complete_independently_with_only_their_selected_photos(): void
    {
        $product = $this->product();
        $firstOwner = $this->owner;
        $first = $this->pendingSubmission($product);
        // Replaced evidence must stay in history, not appear in the new analysis version.
        $this->post("/api/feed-scanner/submissions/$first/photos", ['slot' => 'analysis', 'file' => UploadedFile::fake()->image('replacement.jpg', 30, 30)], ['Accept' => 'application/json'])->assertOk();
        $secondOwner = User::factory()->create();
        $this->owner($secondOwner);
        $second = $this->pendingSubmission($product);
        $this->mock(FeedLabelReader::class, fn ($mock) => $mock->shouldReceive('read')->twice()->andReturn($this->extractedLabel()));
        $results = [];
        foreach ([[$first, $firstOwner], [$second, $secondOwner]] as [$id, $owner]) {
            $this->owner($owner);
            $results[] = $this->postJson("/api/feed-scanner/submissions/$id/analyse")->assertOk()->assertJsonPath('status', 'completed')->json();
            $submission = FeedScanSubmission::findOrFail($id);
            $version = ProductVersion::findOrFail($submission->product_version_id);
            $this->assertSame($id, $version->submission_id);
            $photos = $version->attachments;
            $this->assertCount(3, $photos);
            $this->assertSame([$id], $photos->pluck('submission_id')->unique()->values()->all());
            $selectedPaths = FeedLabelAttachment::whereIn('id', array_values($submission->photo_ids))->pluck('path')->all();
            $this->assertEqualsCanonicalizing($selectedPaths, $photos->pluck('path')->all());
            $this->postJson("/api/feed-scanner/submissions/$id/analyse")->assertJsonPath('scan_result_id', $submission->scan_result_id);
        }
        $this->assertNotSame($results[0]['scan_result_id'], $results[1]['scan_result_id']);
        $this->assertNotSame($results[0]['product_version_id'], $results[1]['product_version_id']);
        $this->assertSame(2, ScanResult::count());
        $this->assertSame($product->published_version_id, $product->fresh()->published_version_id);
        Http::assertNothingSent();
    }

    public function test_reviewer_edit_during_extraction_is_protected_even_after_another_submission_arrives(): void
    {
        $product = $this->product();
        $first = $this->pendingSubmission($product);
        $admin = AdminUser::create(['name' => 'Reviewer', 'email' => 'reviewer@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $edited = null;
        $this->mock(FeedLabelReader::class, function ($mock) use ($product, $admin, &$edited) {
            $mock->shouldReceive('read')->once()->andReturnUsing(function () use ($product, $admin, &$edited) {
                $edited = DB::transaction(fn () => FeedCatalog::productDraft($product, array_replace(FeedCatalog::latestProduct($product)->data, ['name' => 'Naam van beheerder']), $admin->id));
                $this->pendingSubmission($product);

                return $this->extractedLabel();
            });
        });
        $this->postJson("/api/feed-scanner/submissions/$first/analyse")->assertOk()->assertJsonPath('status', 'review_required')->assertJsonPath('processing_token', null);
        $this->assertSame($first, $edited->submission_id);
        $this->assertSame('Naam van beheerder', $edited->fresh()->data['name']);
        $this->assertSame(0, ScanResult::count());
        $this->postJson("/api/feed-scanner/submissions/$first/analyse")->assertJsonPath('status', 'review_required');
    }

    public function test_reviewing_another_submission_does_not_block_this_submission(): void
    {
        $product = $this->product();
        $first = $this->pendingSubmission($product);
        $second = $this->pendingSubmission($product);
        $admin = AdminUser::create(['name' => 'Reviewer', 'email' => 'reviewer@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $edited = DB::transaction(fn () => FeedCatalog::productDraft($product, FeedCatalog::latestProduct($product)->data, $admin->id));
        $this->assertSame($second, $edited->submission_id);
        $this->mock(FeedLabelReader::class, fn ($mock) => $mock->shouldReceive('read')->twice()->andReturn($this->extractedLabel()));
        $this->postJson("/api/feed-scanner/submissions/$first/analyse")->assertOk()->assertJsonPath('status', 'completed');
        $this->postJson("/api/feed-scanner/submissions/$second/analyse")->assertOk()->assertJsonPath('status', 'review_required');
        $this->assertSame(1, ScanResult::count());
    }

    public function test_reviewing_the_submission_draft_in_place_prevents_automatic_replacement(): void
    {
        $product = $this->product();
        $id = $this->pendingSubmission($product);
        $version = ProductVersion::findOrFail(FeedScanSubmission::findOrFail($id)->product_version_id);
        $version->update(['status' => 'needs_info']);
        $this->mock(FeedLabelReader::class, fn ($mock) => $mock->shouldReceive('read')->once()->andReturn($this->extractedLabel()));
        $this->postJson("/api/feed-scanner/submissions/$id/analyse")->assertOk()->assertJsonPath('status', 'review_required');
        $this->assertSame('needs_info', $version->fresh()->status);
        $this->assertSame(0, ScanResult::count());
    }

    public function test_migration_links_existing_drafts_and_reopens_only_unreviewed_blocked_submissions(): void
    {
        $product = $this->product();
        $first = $this->pendingSubmission($product);
        $second = $this->pendingSubmission($product);
        $admin = AdminUser::create(['name' => 'Reviewer', 'email' => 'reviewer@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $edited = DB::transaction(fn () => FeedCatalog::productDraft($product, FeedCatalog::latestProduct($product)->data, $admin->id));
        FeedScanSubmission::whereIn('id', [$first, $second])->update(['status' => 'review_required']);
        $photos = FeedLabelAttachment::orderBy('id')->get()->toArray();
        $migration = require database_path('migrations/2026_10_26_160000_link_product_versions_to_feed_submissions.php');
        $migration->down();
        $migration->up();
        $this->assertSame($second, $edited->fresh()->submission_id);
        $this->assertSame('needs_photos', FeedScanSubmission::findOrFail($first)->status);
        $this->assertSame('review_required', FeedScanSubmission::findOrFail($second)->status);
        $this->assertSame($photos, FeedLabelAttachment::orderBy('id')->get()->toArray());
        $this->assertSame($product->published_version_id, $product->fresh()->published_version_id);
        $this->assertNull(ProductVersion::findOrFail($product->published_version_id)->submission_id);
        $this->mock(FeedLabelReader::class, fn ($mock) => $mock->shouldReceive('read')->once()->andReturn($this->extractedLabel()));
        $this->postJson("/api/feed-scanner/submissions/$first/analyse")->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_known_recipe_reuses_only_the_approved_current_assessment_without_ai_or_extra_photos(): void
    {
        $product = $this->product();
        $this->ocr("Samenstelling:\n".self::RECIPE."\nAnalytische bestanddelen\nRuw eiwit 11 procent");
        $id = $this->submission();
        $this->postJson("/api/feed-scanner/submissions/$id/check")->assertOk()->assertJsonPath('status', 'candidate');
        $this->postJson("/api/feed-scanner/submissions/$id/check")->assertJsonPath('status', 'candidate');
        $response = $this->postJson("/api/feed-scanner/submissions/$id/confirm")->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('public_result.provisional', false)->assertJsonPath('public_result.score', '0');
        $this->postJson("/api/feed-scanner/submissions/$id/confirm")->assertJsonPath('scan_result_id', $response->json('scan_result_id'));
        $this->assertSame(1, Product::count());
        $this->assertSame(1, ProductVersion::count());
        $this->assertSame(1, FeedAssessment::count());
        $this->assertSame(1, ScanResult::count());
        $this->assertSame($product->published_version_id, $response->json('product_version_id'));
        Http::assertNothingSent();
    }

    public function test_changed_recipe_preserves_product_identity_published_version_and_prior_assessment(): void
    {
        $product = $this->product();
        $original = ProductVersion::find($product->published_version_id)->data;
        $this->ocr("Testmerk Balansvoer\nSamenstelling:\nTimotheegras, luzerne, lijnzaad 9%, calciumcarbonaat, zout.\nAnalytische bestanddelen\nRuw eiwit");
        $id = $this->submission($product);
        $s = $this->postJson("/api/feed-scanner/submissions/$id/check")->assertOk()->assertJsonPath('status', 'needs_photos')->assertJsonPath('recognition.decision', 'changed')->json();
        $this->assertSame(1, Product::count());
        $this->assertSame(2, ProductVersion::count());
        $this->assertSame($product->id, $s['product_id']);
        $this->assertSame($product->published_version_id, $s['reference_version_id']);
        $this->assertSame($original, ProductVersion::find($product->published_version_id)->data);
        $draft = ProductVersion::find($s['product_version_id']);
        $this->assertSame([], $draft->data['nutrients']);
        $this->assertSame(1, $draft->attachments()->count());
        $this->postJson("/api/feed-scanner/submissions/$id/analyse")->assertUnprocessable();
        $this->upload($id, 'analysis');
        $this->postJson("/api/feed-scanner/submissions/$id/analyse")->assertUnprocessable();
        $this->assertSame($product->published_version_id, $product->fresh()->published_version_id);
        $this->assertSame(1, FeedAssessment::count());
        Http::assertNothingSent();
    }

    public function test_a_partial_or_uncertain_photo_never_reuses_a_known_score(): void
    {
        $product = $this->product();
        $this->ocr("Testmerk Balansvoer\nSamenstelling:\n".self::RECIPE);
        $id = $this->submission($product);
        $this->postJson("/api/feed-scanner/submissions/$id/check")->assertOk()->assertJsonPath('status', 'needs_photos')->assertJsonPath('recognition.decision', 'uncertain');
        $this->postJson("/api/feed-scanner/submissions/$id/confirm")->assertConflict();
        $this->assertSame(0, ScanResult::count());
        Http::assertNothingSent();
    }

    public function test_shared_recipes_remain_uncertain_even_with_a_product_hint(): void
    {
        $product = $this->product();
        $this->product('Ander product');
        $this->ocr();
        $id = $this->submission($product);
        $this->postJson("/api/feed-scanner/submissions/$id/check")->assertOk()->assertJsonPath('status', 'needs_photos');
        Http::assertNothingSent();
    }

    public function test_owner_reported_changes_create_a_review_version_without_overwriting_the_published_recipe(): void
    {
        $product = $this->product();
        $this->ocr();
        $id = $this->submission();
        $this->postJson("/api/feed-scanner/submissions/$id/check")->assertJsonPath('status', 'candidate');
        $this->postJson("/api/feed-scanner/submissions/$id/more-photos", ['changed' => true])->assertJsonPath('status', 'needs_photos')->assertJsonPath('recognition.owner_reported_change', true);
        $this->assertSame(1, Product::count());
        $this->assertSame(2, ProductVersion::count());
        $this->assertSame($product->published_version_id, $product->fresh()->published_version_id);
        Http::assertNothingSent();
    }

    public function test_rejecting_a_product_candidate_does_not_attach_an_unknown_feed_to_that_product(): void
    {
        $this->product();
        $this->ocr();
        $id = $this->submission();
        $this->postJson("/api/feed-scanner/submissions/$id/check")->assertJsonPath('status', 'candidate');
        $this->postJson("/api/feed-scanner/submissions/$id/more-photos", ['changed' => false])->assertJsonPath('status', 'needs_photos')->assertJsonPath('reference_product_id', null);
        $this->assertSame(2, Product::count());
        Http::assertNothingSent();
    }

    public function test_ocr_failure_keeps_the_photo_and_requests_supplementary_evidence_without_paid_ai(): void
    {
        $this->mock(FeedCompositionOcr::class, fn ($mock) => $mock->shouldReceive('read')->once()->andThrow(new \RuntimeException('ocr_unavailable')));
        $id = $this->submission();
        $this->postJson("/api/feed-scanner/submissions/$id/check")->assertOk()->assertJsonPath('status', 'needs_photos')->assertJsonPath('recognition.decision', 'uncertain');
        $this->assertCount(1, FeedScanSubmission::find($id)->photo_ids);
        Http::assertNothingSent();
    }

    public function test_other_users_cannot_check_confirm_or_change_the_submission(): void
    {
        $id = $this->submission();
        $this->owner(User::factory()->create());
        foreach (['check', 'confirm', 'more-photos'] as $action) {
            $this->postJson("/api/feed-scanner/submissions/$id/$action", ['changed' => true])->assertNotFound();
        }
        Http::assertNothingSent();
    }

    public function test_changed_published_pointer_cannot_confirm_an_outdated_match(): void
    {
        $product = $this->product();
        $this->ocr();
        $id = $this->submission();
        $this->postJson("/api/feed-scanner/submissions/$id/check")->assertJsonPath('status', 'candidate');
        $next = FeedCatalog::productDraft($product, ProductVersion::find($product->published_version_id)->data, null);
        $next->update(['status' => 'approved']);
        $product->forceFill(['published_version_id' => $next->id])->save();
        $this->postJson("/api/feed-scanner/submissions/$id/confirm")->assertJsonPath('status', 'needs_photos');
        $this->assertSame(0, ScanResult::count());
        Http::assertNothingSent();
    }

    public function test_recognition_does_not_drop_additive_evidence_from_the_reviewed_reference(): void
    {
        $product = $this->product();
        $data = ProductVersion::find($product->published_version_id)->data;
        $data['composition_text'] = "Samenstelling:\n".self::RECIPE."\nAnalytische bestanddelen\nEiwit 11%\nToevoegingen:\nNatriumseleniet";
        $version = FeedCatalog::productDraft($product, $data, null);
        $version->update(['status' => 'approved']);
        $product->forceFill(['published_version_id' => $version->id])->save();
        $this->ocr();
        $id = $this->submission($product);
        $this->postJson("/api/feed-scanner/submissions/$id/check")->assertJsonPath('status', 'needs_photos');
        Http::assertNothingSent();
    }

    public function test_a_recipe_with_unseen_selenium_evidence_cannot_reuse_its_score(): void
    {
        $product = $this->product();
        $data = ProductVersion::find($product->published_version_id)->data;
        $data['ingredients'] = [['label' => 'Seleniumgist', 'added_selenium' => 'confirmed', 'selenium_form' => 'organic', 'evidence' => 'Toevoegingen: seleniumgist']];
        $version = FeedCatalog::productDraft($product, $data, null);
        $version->update(['status' => 'approved']);
        $product->forceFill(['published_version_id' => $version->id])->save();
        $this->ocr();
        $id = $this->submission($product);
        $this->postJson("/api/feed-scanner/submissions/$id/check")->assertJsonPath('status', 'needs_photos');
        Http::assertNothingSent();
    }

    public function test_front_label_can_link_an_initially_unidentified_recipe_to_the_existing_product_for_review(): void
    {
        $product = $this->product();
        $this->ocr("Samenstelling:\nTimotheegras, luzerne, lijnzaad 9%, calciumcarbonaat, zout.\nAnalytische bestanddelen\nRuw eiwit 11%");
        $id = $this->submission();
        $pending = $this->postJson("/api/feed-scanner/submissions/$id/check")->assertJsonPath('status', 'needs_photos')->json();
        $this->assertNotSame($product->id, $pending['product_id']);
        $this->upload($id, 'analysis');
        $this->upload($id, 'front');
        config(['feed-scanner.api_key' => 'test-only']);
        $raw = ['brand' => 'Testmerk', 'name' => 'Testmerk Balansvoer', 'barcode' => null, 'category' => 'complementary', 'category_confidence' => 'high',
            'composition_text' => 'Timotheegras, luzerne, lijnzaad 9%, calciumcarbonaat, zout.', 'composition_confidence' => 'high', 'ingredients' => [], 'unreadable_fields' => [],
            'nutrients' => [['code' => 'sugar', 'value' => '3', 'unit' => '%', 'basis' => 'product', 'qualifier' => 'exact', 'confidence' => 'high', 'source' => 'Suiker 3% per kg product']]];
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($raw)]]]]])]);
        $s = $this->postJson("/api/feed-scanner/submissions/$id/analyse")->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('product_id', $product->id)->assertJsonPath('public_result.provisional', true)->json();
        $this->assertSame($product->published_version_id, $s['reference_version_id']);
        $this->assertSame($product->published_version_id, $product->fresh()->published_version_id);
        $this->assertDatabaseHas('products', ['id' => $pending['product_id'], 'catalog_status' => 'archived']);
        $this->assertSame(2, ProductVersion::where('product_id', $product->id)->count());
        $new = ProductVersion::find($s['product_version_id']);
        $this->assertCount(1, $new->data['nutrients']); // No old starch value is borrowed.
        $this->assertSame(3, $new->attachments()->count()); // Only this submission's three photos.
        Http::assertSentCount(1);
    }
}
