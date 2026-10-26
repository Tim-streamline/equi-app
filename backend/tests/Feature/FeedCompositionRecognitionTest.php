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
            'ingredients' => [], 'nutrients' => [['code' => 'sugar', 'value' => '6', 'unit' => '%', 'basis' => 'product', 'qualifier' => 'exact', 'confidence' => 'high', 'source' => 'Suiker 6% per kg product']], 'unreadable_fields' => []];
    }

    private function admin(): AdminUser
    {
        return AdminUser::firstOrCreate(['email' => 'reviewer@example.com'], ['name' => 'Reviewer', 'password' => 'password', 'role' => 'admin', 'active' => true]);
    }

    private function draftFor(string $id, array $data): ProductVersion
    {
        $s = FeedScanSubmission::findOrFail($id);
        $version = DB::transaction(fn () => FeedCatalog::productDraft(Product::findOrFail($s->product_id), $data, $this->admin()->id));
        $s->update(['product_version_id' => $version->id]);

        return $version;
    }

    public function test_duplicate_complete_labels_share_a_product_revision_and_assessment_but_keep_private_evidence(): void
    {
        $reference = $this->product();
        $firstOwner = $this->owner;
        $first = $this->pendingSubmission($reference);
        $secondOwner = User::factory()->create();
        $this->owner($secondOwner);
        $second = $this->pendingSubmission($reference);
        $this->mock(FeedLabelReader::class, fn ($mock) => $mock->shouldReceive('read')->twice()->andReturn($this->extractedLabel()));
        $results = [];
        foreach ([[$first, $firstOwner], [$second, $secondOwner]] as [$id, $owner]) {
            $this->owner($owner);
            $results[] = $this->postJson("/api/feed-scanner/submissions/$id/analyse")->assertOk()->assertJsonPath('status', 'completed')->json();
            $this->postJson("/api/feed-scanner/submissions/$id/analyse")->assertJsonPath('scan_result_id', end($results)['scan_result_id']);
        }
        $this->assertNotSame($reference->id, $results[0]['product_id']);
        $this->assertSame($results[0]['product_id'], $results[1]['product_id']);
        $this->assertSame($results[0]['product_version_id'], $results[1]['product_version_id']);
        $this->assertSame($results[0]['assessment_id'], $results[1]['assessment_id']);
        $this->assertNotSame($results[0]['scan_result_id'], $results[1]['scan_result_id']);
        $this->assertSame(1, ProductVersion::where('product_id', $results[0]['product_id'])->count());
        $this->assertNull(ProductVersion::findOrFail($results[0]['product_version_id'])->submission_id);
        $this->assertCount(3, FeedLabelAttachment::where('submission_id', $second)->get());
        $this->getJson("/api/feed-scanner/submissions/$first")->assertNotFound();
        $this->actingAs($this->admin(), 'admin');
        $this->get('/admin/feed-scanner/products?open='.$results[0]['product_id'])->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->has('products.data.0.submissions', 2)->has('products.data.0.submissions.0.attachments', 3)->has('products.data.0.submissions.1.attachments', 3));
    }

    public function test_an_archived_recipe_does_not_block_a_new_submission_with_the_same_label(): void
    {
        $reference = $this->product();
        $first = $this->pendingSubmission($reference);
        $second = $this->pendingSubmission($reference);
        $this->mock(FeedLabelReader::class, fn ($mock) => $mock->shouldReceive('read')->twice()->andReturn($this->extractedLabel()));
        $oldId = $this->postJson("/api/feed-scanner/submissions/$first/analyse")->assertJsonPath('status', 'completed')->json('product_id');
        Product::findOrFail($oldId)->forceFill(['catalog_status' => 'archived'])->save();
        $newId = $this->postJson("/api/feed-scanner/submissions/$second/analyse")->assertJsonPath('status', 'completed')->json('product_id');
        $this->assertNotSame($oldId, $newId);
        $this->assertSame('archived', Product::findOrFail($oldId)->catalog_status);
    }

    public function test_different_recipes_or_analysis_values_never_share_a_product(): void
    {
        $reference = $this->product();
        $first = $this->pendingSubmission($reference);
        $second = $this->pendingSubmission($reference);
        $third = $this->pendingSubmission($reference);
        $one = $this->extractedLabel();
        $two = array_replace($one, ['composition_text' => str_replace('7,88%', '9%', self::RECIPE)]);
        $three = $one;
        $three['nutrients'][0]['value'] = '7';
        $this->mock(FeedLabelReader::class, fn ($mock) => $mock->shouldReceive('read')->times(3)->andReturn($one, $two, $three));
        $products = [];
        foreach ([$first, $second, $third] as $id) {
            $products[] = $this->postJson("/api/feed-scanner/submissions/$id/analyse")->assertJsonPath('status', 'completed')->json('product_id');
        }
        $this->assertCount(3, array_unique($products));
        $this->assertNotContains($reference->id, $products);
        $this->assertSame(1, ProductVersion::where('product_id', $reference->id)->count());
    }

    public function test_incoming_scans_do_not_supersede_an_admin_correction_or_block_its_score(): void
    {
        $reference = $this->product();
        $admin = $this->admin();
        $manual = FeedCatalog::productDraft($reference, ProductVersion::findOrFail($reference->published_version_id)->data, $admin->id);
        $this->pendingSubmission($reference);
        $this->assertSame($manual->id, FeedCatalog::latestProduct($reference)->id);
        $this->actingAs($admin, 'admin');
        $this->post("/admin/feed-scanner/products/{$reference->id}/review", ['version_id' => $manual->id, 'decision' => 'approve'])->assertSessionHasNoErrors()->assertRedirect();
        $assessment = app(FeedAssessments::class)->calculate($reference, $manual->fresh());
        $this->post("/admin/feed-scanner/assessments/{$assessment->id}/correct", ['score' => '0', 'reason' => 'Gecontroleerd etiket'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, $assessment->overrides()->count());
    }

    public function test_reviewer_corrections_during_extraction_are_never_overwritten(): void
    {
        $reference = $this->product();
        $id = $this->pendingSubmission($reference);
        $data = array_replace(ProductVersion::findOrFail($reference->published_version_id)->data, ['name' => 'Naam van beheerder']);
        $edited = null;
        $this->mock(FeedLabelReader::class, function ($mock) use ($id, $data, &$edited) {
            $mock->shouldReceive('read')->once()->andReturnUsing(function () use ($id, $data, &$edited) {
                $edited = $this->draftFor($id, $data);
                return $this->extractedLabel();
            });
        });
        $this->postJson("/api/feed-scanner/submissions/$id/analyse")->assertOk()->assertJsonPath('status', 'review_required');
        $this->assertSame('Naam van beheerder', $edited->fresh()->data['name']);
        $this->assertSame(0, ScanResult::count());
    }

    public function test_approval_completes_only_evidence_for_that_product_and_preserves_old_scans(): void
    {
        $reference = $this->product();
        $first = $this->pendingSubmission($reference);
        $second = $this->pendingSubmission($reference);
        $data = ProductVersion::findOrFail($reference->published_version_id)->data;
        $version = $this->draftFor($first, $data);
        $product = Product::findOrFail($version->product_id);
        $this->actingAs($this->admin(), 'admin');
        $this->post("/admin/feed-scanner/products/{$product->id}/review", ['version_id' => $version->id, 'decision' => 'approve'])->assertSessionHasNoErrors()->assertRedirect();
        $s = FeedScanSubmission::findOrFail($first);
        $this->assertSame('completed', $s->status);
        $this->assertFalse($s->public_result['provisional']);
        $this->assertSame('needs_photos', FeedScanSubmission::findOrFail($second)->status);
        $before = ScanResult::findOrFail($s->scan_result_id)->toArray();
        $snapshot = $s->public_result;
        $next = FeedCatalog::productDraft($product, array_replace($data, ['name' => 'Gecorrigeerde spelling']), $this->admin()->id);
        DB::transaction(fn () => FeedCatalog::approveProduct($product, $next, $this->admin()->id, null));
        $this->assertSame($before, ScanResult::findOrFail($s->scan_result_id)->toArray());
        $this->assertSame($snapshot, $s->fresh()->public_result);
        $this->getJson("/api/feed-scanner/submissions/$first")->assertJsonPath('scan_result_id', $s->scan_result_id);
        $this->assertSame(1, ScanResult::count());
    }

    public function test_stale_corrections_foreign_versions_and_unauthorised_approval_are_rejected(): void
    {
        $reference = $this->product();
        $id = $this->pendingSubmission($reference);
        $source = $this->draftFor($id, ProductVersion::findOrFail($reference->published_version_id)->data);
        $product = Product::findOrFail($source->product_id);
        $this->actingAs($this->admin(), 'admin');
        $payload = $source->data + ['source_version_id' => $source->id, 'expected_number' => $source->number];
        $this->put("/admin/feed-scanner/products/{$product->id}", $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->put("/admin/feed-scanner/products/{$product->id}", $payload)->assertStatus(409);
        $this->post("/admin/feed-scanner/products/{$product->id}/review", ['version_id' => $source->id, 'decision' => 'approve'])->assertStatus(409);
        $this->post("/admin/feed-scanner/versions/{$source->id}/attachments", ['purpose' => 'analysis', 'file' => UploadedFile::fake()->image('stale.jpg')])->assertStatus(409);
        $this->put("/admin/feed-scanner/products/{$reference->id}", $payload)->assertNotFound();
        $this->admin()->update(['role' => 'content_editor']);
        $this->actingAs($this->admin()->fresh(), 'admin');
        $this->post("/admin/feed-scanner/products/{$product->id}/review", ['version_id' => FeedCatalog::latestProduct($product)->id, 'decision' => 'approve'])->assertForbidden();
    }

    public function test_admin_can_create_a_changed_recipe_with_the_same_barcode_as_a_separate_product(): void
    {
        $reference = $this->product();
        $reference->update(['barcode' => '8712345678901']);
        $this->actingAs($this->admin(), 'admin');
        $data = array_replace(ProductVersion::findOrFail($reference->published_version_id)->data,
            ['barcode' => $reference->barcode, 'composition_text' => 'Luzerne, timothee, lijnzaad 9%, calciumcarbonaat, zout.', 'related_product_id' => $reference->id, 'approve' => true]);
        $this->post('/admin/feed-scanner/products', $data)->assertSessionHasNoErrors()->assertRedirect();
        $new = Product::where('id', '!=', $reference->id)->sole();
        $this->assertSame($reference->id, $new->related_product_id);
        $this->assertSame($reference->barcode, $new->barcode);
        $this->assertSame(1, ProductVersion::where('product_id', $reference->id)->count());
        $this->assertSame('published', $new->catalog_status);
    }

    public function test_same_name_different_recipes_can_each_be_recognised(): void
    {
        $original = $this->product();
        $changed = $this->product();
        $data = ProductVersion::findOrFail($changed->published_version_id)->data;
        $data['composition_text'] = str_replace('7,88%', '9%', self::RECIPE);
        $version = FeedCatalog::productDraft($changed, $data, null);
        $version->update(['status' => 'approved']);
        $changed->forceFill(['published_version_id' => $version->id])->save();
        $this->ocr();
        $id = $this->submission();
        $this->postJson("/api/feed-scanner/submissions/$id/check")->assertJsonPath('status', 'candidate')->assertJsonPath('reference_product_id', $original->id);
    }

    public function test_migration_separates_existing_review_work_without_rewriting_scan_snapshots(): void
    {
        $reference = $this->product();
        $manual = FeedCatalog::productDraft($reference, ProductVersion::findOrFail($reference->published_version_id)->data, $this->admin()->id);
        $s = FeedScanSubmission::create(['user_id' => $this->owner->id, 'client_id' => (string) Str::uuid(),
            'product_id' => $reference->id, 'workflow_version' => 2, 'status' => 'completed', 'public_result' => ['name' => 'Historical result']]);
        $legacy = FeedCatalog::productDraft($reference, array_replace($manual->data, ['composition_text' => 'Andere samenstelling']), null);
        // Simulate the old schema's per-submission revision ownership.
        DB::table('product_versions')->where('id', $legacy->id)->update(['submission_id' => $s->id]);
        $scan = ScanResult::create(['user_id' => $this->owner->id, 'product_id' => $reference->id, 'product_name' => 'Historical result', 'brand' => 'Testmerk', 'scanned_at' => now(), 'score' => null, 'rating' => 'Voorlopig']);
        $s->update(['product_version_id' => $legacy->id, 'scan_result_id' => $scan->id]);
        $this->uploadLegacyPhoto($s, $legacy);
        $snapshot = $scan->fresh()->toArray();
        $migration = require database_path('migrations/2026_10_26_170000_separate_feed_recipes_from_submissions.php');
        $migration->separateExistingSubmissions();
        $s->refresh();
        $this->assertNotSame($reference->id, $s->product_id);
        $this->assertSame($manual->id, FeedCatalog::latestProduct($reference)->id);
        $this->assertTrue($reference->fresh()->needs_review);
        $this->assertSame($s->product_id, $legacy->fresh()->superseded_by_product_id);
        $copy = ProductVersion::findOrFail($s->product_version_id);
        $this->assertSame($legacy->data, $copy->data);
        $this->assertNull($copy->submission_id);
        $this->assertSame($legacy->attachments()->value('path'), $copy->attachments()->value('path'));
        $this->assertSame($snapshot, $scan->fresh()->toArray());
        $this->assertSame(['name' => 'Historical result'], $s->public_result);
        $migration->separateExistingSubmissions();
        $this->assertSame(2, Product::count());
    }

    private function uploadLegacyPhoto(FeedScanSubmission $s, ProductVersion $version): void
    {
        $photo = FeedLabelAttachment::create(['product_version_id' => $version->id, 'submission_id' => $s->id,
            'submitted_by' => $s->user_id, 'purpose' => 'composition', 'disk' => 'local', 'path' => 'legacy.jpg',
            'original_name' => 'legacy.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'checksum' => hash('sha256', 'legacy')]);
        $s->update(['photo_ids' => ['composition' => $photo->id]]);
    }

    public function test_approval_during_extraction_finishes_once_without_overwriting_review(): void
    {
        $reference = $this->product();
        $id = $this->pendingSubmission($reference);
        $data = array_replace(ProductVersion::findOrFail($reference->published_version_id)->data, ['name' => 'Naam uit review']);
        $this->mock(FeedLabelReader::class, function ($mock) use ($id, $data) {
            $mock->shouldReceive('read')->once()->andReturnUsing(function () use ($id, $data) {
                $version = $this->draftFor($id, $data);
                DB::transaction(fn () => FeedCatalog::approveProduct(Product::findOrFail($version->product_id), $version, $this->admin()->id, null));
                return $this->extractedLabel();
            });
        });
        $this->postJson("/api/feed-scanner/submissions/$id/analyse")->assertJsonPath('status', 'completed')
            ->assertJsonPath('public_result.name', 'Naam uit review')->assertJsonPath('public_result.provisional', false);
        $this->assertSame(1, ScanResult::count());
    }

    public function test_uncertain_label_facts_do_not_merge_pending_products(): void
    {
        $reference = $this->product();
        $first = $this->pendingSubmission($reference);
        $second = $this->pendingSubmission($reference);
        $raw = $this->extractedLabel();
        $raw['unreadable_fields'] = ['Toevoegingen niet leesbaar'];
        $this->mock(FeedLabelReader::class, fn ($mock) => $mock->shouldReceive('read')->twice()->andReturn($raw));
        $one = $this->postJson("/api/feed-scanner/submissions/$first/analyse")->assertJsonPath('status', 'completed')->json('product_id');
        $two = $this->postJson("/api/feed-scanner/submissions/$second/analyse")->assertJsonPath('status', 'completed')->json('product_id');
        $this->assertNotSame($one, $two);
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

    public function test_changed_recipe_creates_a_separate_product_and_preserves_the_original(): void
    {
        $product = $this->product();
        $original = ProductVersion::find($product->published_version_id)->data;
        $this->ocr("Testmerk Balansvoer\nSamenstelling:\nTimotheegras, luzerne, lijnzaad 9%, calciumcarbonaat, zout.\nAnalytische bestanddelen\nRuw eiwit");
        $id = $this->submission($product);
        $s = $this->postJson("/api/feed-scanner/submissions/$id/check")->assertOk()->assertJsonPath('status', 'needs_photos')->assertJsonPath('recognition.decision', 'changed')->json();
        $this->assertSame(2, Product::count());
        $this->assertSame(1, ProductVersion::count());
        $this->assertNotSame($product->id, $s['product_id']);
        $this->assertSame($product->id, Product::findOrFail($s['product_id'])->related_product_id);
        $this->assertSame($product->published_version_id, $s['reference_version_id']);
        $this->assertSame($original, ProductVersion::find($product->published_version_id)->data);
        $this->assertNull($s['product_version_id']);
        $this->assertSame(1, FeedLabelAttachment::where('submission_id', $id)->count());
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

    public function test_owner_reported_changes_create_a_separate_product_without_overwriting_the_original(): void
    {
        $product = $this->product();
        $this->ocr();
        $id = $this->submission();
        $this->postJson("/api/feed-scanner/submissions/$id/check")->assertJsonPath('status', 'candidate');
        $this->postJson("/api/feed-scanner/submissions/$id/more-photos", ['changed' => true])->assertJsonPath('status', 'needs_photos')->assertJsonPath('recognition.owner_reported_change', true);
        $this->assertSame(2, Product::count());
        $this->assertSame(1, ProductVersion::count());
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

    public function test_front_label_only_links_the_related_product_without_merging_recipes(): void
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
        $s = $this->postJson("/api/feed-scanner/submissions/$id/analyse")->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('product_id', $pending['product_id'])->assertJsonPath('public_result.provisional', true)->json();
        $this->assertSame($product->published_version_id, $s['reference_version_id']);
        $this->assertSame($product->published_version_id, $product->fresh()->published_version_id);
        $this->assertDatabaseHas('products', ['id' => $pending['product_id'], 'catalog_status' => 'draft', 'related_product_id' => $product->id]);
        $this->assertSame(1, ProductVersion::where('product_id', $product->id)->count());
        $new = ProductVersion::find($s['product_version_id']);
        $this->assertCount(1, $new->data['nutrients']); // No old starch value is borrowed.
        $this->assertSame(3, $new->attachments()->count()); // Only this submission's three photos.
        Http::assertSentCount(1);
    }
}
