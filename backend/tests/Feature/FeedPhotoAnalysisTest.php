<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\AdminUser;
use App\Models\FeedAnalysisPrompt;
use App\Models\FeedLabelAttachment;
use App\Models\FeedScanSubmission;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use App\Support\FeedCatalog;
use App\Support\FeedCompositionOcr;
use App\Support\FeedPhotoAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FeedPhotoAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['feed-scanner.api_key' => 'test-only']);
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
        $this->mock(FeedCompositionOcr::class, fn ($mock) => $mock->shouldReceive('read')->andReturn([
            'text' => 'OCR-only: definitely not a Luna ingredient', 'confidence' => 96, 'words' => 8, 'low_confidence_words' => 1, 'engine' => 'test-ocr',
        ]));
    }

    private function admin(string $role = 'admin'): AdminUser
    {
        return AdminUser::create(['name' => 'Reviewer', 'email' => Str::uuid().'@example.com', 'password' => 'password', 'role' => $role, 'active' => true]);
    }

    private function raw(): array
    {
        return ['raw_text' => "Samenstelling: Luzerne 80%\nAnalyse: suiker 4,2% per kg product", 'brand' => 'Luna merk', 'name' => 'Luna product', 'barcode' => null,
            'category' => 'complementary', 'category_confidence' => 'high', 'composition_text' => 'Luzerne 80%', 'composition_confidence' => 'high',
            'ingredients' => [['label' => 'Luzerne', 'amount' => '80%', 'evidence' => 'Luzerne 80%', 'confidence' => 'high', 'added_selenium' => 'unknown', 'selenium_form' => 'unknown']],
            'nutrients' => [['code' => 'sugar', 'value' => '4.2', 'unit' => '%', 'basis' => 'product', 'qualifier' => 'exact', 'confidence' => 'high', 'source' => 'suiker 4,2% per kg product']],
            'unreadable_fields' => []];
    }

    private function answer(?array $raw = null)
    {
        return Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($raw ?? $this->raw())]]]],
            'usage' => ['input_tokens' => 1234, 'output_tokens' => 567, 'total_tokens' => 1801]]);
    }

    private function submission(): FeedScanSubmission
    {
        return FeedScanSubmission::create(['user_id' => $this->owner->id, 'client_id' => Str::uuid(), 'workflow_version' => 2, 'status' => 'awaiting_composition']);
    }

    private function upload(FeedScanSubmission $s, string $slot, int $width = 100)
    {
        return $this->post("/api/feed-scanner/submissions/{$s->id}/photos", ['slot' => $slot, 'file' => UploadedFile::fake()->image($slot.'.jpg', $width, 100)], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_every_photo_has_independent_readers_and_only_luna_populates_the_product(): void
    {
        Http::fake(['api.openai.com/*' => fn () => $this->answer()]);
        config(['feed-scanner.model' => 'old-configured-model']);
        $s = $this->submission();
        $this->upload($s, 'composition')->assertJsonMissingPath('photos.0.luna')->assertJsonMissingPath('ingredients_photo_luna_prompt')->assertJsonPath('suggested_name', null);
        $this->postJson("/api/feed-scanner/submissions/{$s->id}/check")->assertJsonPath('status', 'needs_photos');
        $this->upload($s, 'analysis')->assertJsonPath('suggested_name', null);
        $this->upload($s, 'front')->assertJsonPath('suggested_name', 'Luna merk Luna product')->assertJsonMissingPath('product_photo_luna_data');
        $this->getJson("/api/feed-scanner/submissions/{$s->id}")->assertJsonPath('suggested_name', 'Luna merk Luna product');
        $s->refresh();
        foreach (array_keys(FeedScanSubmission::PHOTO_SLOTS) as $slot) {
            $this->assertSame('completed', $s->photoOcr($slot)['status']);
            $this->assertSame('completed', $s->photoLuna($slot)['status']);
            $this->assertSame(1234, $s->photoLuna($slot)['usage']['input_tokens']);
        }
        $this->assertSame([], $s->photoLuna('composition')['data']['nutrients']);
        $this->assertSame([], $s->photoLuna('analysis')['data']['ingredients']);
        $this->assertSame([], $s->photoLuna('front')['data']['ingredients']);
        $this->postJson("/api/feed-scanner/submissions/{$s->id}/analyse")->assertJsonPath('status', 'completed');
        $s->refresh();
        $version = ProductVersion::findOrFail($s->product_version_id);
        $this->assertSame('Luzerne', $version->data['ingredients'][0]['label']);
        $this->assertSame('4.2', $version->data['nutrients'][0]['value']);
        $this->assertStringNotContainsString('OCR-only', json_encode($version->data));
        $this->assertSame('draft', $version->status);
        $this->assertSame('Luna product', $version->data['name']);
        $this->assertSame('Luna product', $s->product->name);
        $this->postJson("/api/feed-scanner/submissions/{$s->id}/analyse")->assertJsonPath('status', 'completed');
        Http::assertSentCount(3);
        foreach (['composition', 'analysis', 'packaging'] as $type) {
            Http::assertSent(fn (Request $request) => $request['model'] === 'gpt-6-luna'
                && str_contains($request['instructions'], FeedAnalysisPrompt::findOrFail($type)->prompt)
                && ! str_contains(json_encode($request->data()), 'OCR-only')
                && count($request['input'][0]['content']) === 2 && $request['store'] === false);
        }
        $this->actingAs($this->admin(), 'admin')->get('/admin/feed-scanner/products?open='.$s->product_id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data.0.submissions.0.photos.0.ocr.text', 'OCR-only: definitely not a Luna ingredient')
            ->where('products.data.0.submissions.0.photos.0.luna.data.ingredients.0.label', 'Luzerne')
            ->where('products.data.0.submissions.0.photos.1.luna.data.nutrients.0.value', '4.2'));
    }

    public static function frontPhotoNames(): array
    {
        return [
            ['Vitalbix', 'Nutrimash', 'Vitalbix Nutrimash'],
            ['Vitalbix', 'Vitalbix Nutrimash', 'Vitalbix Nutrimash'],
            ['Vitalbix', 'VITALBIX Nutrimash', 'VITALBIX Nutrimash'],
            [null, 'Nutrimash', 'Nutrimash'],
            ['Vitalbix', null, null],
            ['Vitalbix', 'unknown', null],
        ];
    }

    #[DataProvider('frontPhotoNames')]
    public function test_front_photo_exposes_only_a_readable_product_name(?string $brand, ?string $name, ?string $expected): void
    {
        $raw = array_replace($this->raw(), ['brand' => $brand, 'name' => $name]);
        Http::fake(['api.openai.com/*' => fn () => $this->answer($raw)]);
        $s = $this->submission();
        $s->update(['status' => 'needs_photos']);
        $this->upload($s, 'front')->assertJsonPath('suggested_name', $expected)->assertJsonMissingPath('photos.0.luna');
    }

    public function test_known_product_has_no_front_photo_name_suggestion_in_upload_or_resume(): void
    {
        Http::fake(['api.openai.com/*' => fn () => $this->answer()]);
        $known = Product::create(['brand' => 'Known brand', 'name' => 'Known product']);
        $s = $this->submission();
        $s->update(['status' => 'needs_photos', 'reference_product_id' => $known->id, 'submitted_name' => 'Known brand Known product']);

        $this->upload($s, 'front')->assertJsonPath('suggested_name', null)->assertJsonPath('submitted_name', 'Known brand Known product');
        $this->getJson("/api/feed-scanner/submissions/{$s->id}")->assertJsonPath('suggested_name', null)->assertJsonPath('submitted_name', 'Known brand Known product');
        $this->assertSame('Luna product', $s->fresh()->photoLuna('front')['data']['name']);
        $this->assertSame('Known product', $known->fresh()->name);
    }

    public function test_failed_front_replacement_clears_the_previous_name_suggestion(): void
    {
        $calls = 0;
        Http::fake(['api.openai.com/*' => function () use (&$calls) {
            return ++$calls === 1 ? $this->answer() : Http::response([], 503);
        }]);
        $s = $this->submission();
        $s->update(['status' => 'needs_photos']);
        $this->upload($s, 'front')->assertJsonPath('suggested_name', 'Luna merk Luna product');
        $this->upload($s, 'front', 200)->assertJsonPath('suggested_name', null);
        $this->getJson("/api/feed-scanner/submissions/{$s->id}")->assertJsonPath('suggested_name', null);
    }

    public function test_ocr_failure_does_not_prevent_luna_and_luna_failure_preserves_ocr(): void
    {
        $this->mock(FeedCompositionOcr::class, fn ($mock) => $mock->shouldReceive('read')->once()->andThrow(new \RuntimeException('ocr_unavailable')));
        Http::fake(['api.openai.com/*' => fn () => $this->answer()]);
        $s = $this->submission();
        $this->upload($s, 'composition');
        $this->assertSame('failed', $s->fresh()->photoOcr('composition')['status']);
        $this->assertSame('completed', $s->fresh()->photoLuna('composition')['status']);
    }

    public function test_failed_luna_retries_without_repeating_successful_ocr_or_using_ocr_as_fallback(): void
    {
        $this->mock(FeedCompositionOcr::class, fn ($mock) => $mock->shouldReceive('read')->once()->andReturn(['text' => 'Saved OCR', 'engine' => 'test', 'confidence' => 99]));
        $calls = 0;
        Http::fake(['api.openai.com/*' => function () use (&$calls) {
            return ++$calls === 1 ? Http::response([], 503) : $this->answer();
        }]);
        $s = $this->submission();
        $this->upload($s, 'composition');
        $this->assertSame('Saved OCR', $s->fresh()->photoOcr('composition')['text']);
        $this->assertNull($s->fresh()->photoLuna('composition')['data']);
        $photo = $s->fresh()->photo('composition');
        $this->actingAs($this->admin(), 'admin');
        $url = "/admin/feed-scanner/submissions/{$s->id}/photos/{$photo->id}/analyse";
        $this->post($url)->assertRedirect();
        $this->post($url)->assertRedirect();
        Http::assertSentCount(2);
        $this->assertSame('completed', $s->fresh()->photoLuna('composition')['status']);
        $this->assertSame('Saved OCR', $s->fresh()->photoOcr('composition')['text']);
    }

    public function test_late_luna_result_is_discarded_when_photo_is_replaced(): void
    {
        $s = $this->submission();
        $replacement = (string) Str::uuid();
        Http::fake(['api.openai.com/*' => function () use ($s, $replacement) {
            // Duplicate concurrent analysis cannot acquire the in-flight photo's lease.
            app(FeedPhotoAnalysis::class)->analyse($s, 'composition');
            FeedScanSubmission::whereKey($s->id)->update(['ingredients_photo_id' => $replacement, 'ingredients_photo_checksum' => hash('sha256', 'new'),
                'ingredients_photo_ocr_status' => null, 'ingredients_photo_ocr_text' => null, 'ingredients_photo_analysis_token' => null]);

            return $this->answer();
        }]);
        $this->upload($s, 'composition');
        $this->assertSame($replacement, $s->fresh()->ingredients_photo_id);
        $this->assertNull($s->fresh()->photoLuna('composition'));
        Http::assertSentCount(1);
    }

    public function test_prompt_edits_are_authorized_versioned_and_do_not_rewrite_past_results(): void
    {
        Http::fake(['api.openai.com/*' => fn () => $this->answer()]);
        $s = $this->submission();
        $this->upload($s, 'composition');
        $old = $s->fresh()->photoLuna('composition')['prompt'];
        $url = '/admin/feed-scanner/analysis-prompts/composition';
        $this->actingAs($this->admin('content_editor'), 'admin')->put($url, ['prompt' => 'New', 'version' => 1])->assertForbidden();
        $this->actingAs($this->admin(), 'admin')->put($url, ['prompt' => '   ', 'version' => 1])->assertSessionHasErrors('prompt');
        $this->put($url, ['prompt' => 'Lees exact de ingrediënten. Raad niets.', 'version' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->put($url, ['prompt' => 'Stale', 'version' => 1])->assertConflict();
        $this->assertSame($old, $s->fresh()->photoLuna('composition')['prompt']);
        $this->upload($s, 'composition', 120);
        $this->assertSame(2, $s->fresh()->photoLuna('composition')['prompt_version']);
        $this->assertSame('Lees exact de ingrediënten. Raad niets.', $s->fresh()->photoLuna('composition')['prompt']);
        $this->get('/admin/feed-scanner/settings')->assertInertia(fn (AssertableInertia $page) => $page->has('analysisPrompts', 4));
    }

    public function test_invalid_luna_output_and_missing_configuration_are_visible_without_losing_the_photo(): void
    {
        Http::fake(['api.openai.com/*' => fn () => $this->answer(['ingredients' => 'invalid'])]);
        $s = $this->submission();
        $this->upload($s, 'composition');
        $this->assertSame('reader_invalid_output', $s->fresh()->photoLuna('composition')['error_code']);
        Storage::disk('local')->assertExists($s->fresh()->ingredients_photo_path);
        config(['feed-scanner.api_key' => null]);
        $this->upload($s, 'composition', 120);
        $this->assertSame('reader_not_configured', $s->fresh()->photoLuna('composition')['error_code']);
        $this->assertSame('completed', $s->fresh()->photoOcr('composition')['status']);
        Http::assertSentCount(1);
    }

    public function test_admin_photo_creates_a_draft_from_luna_and_retains_the_other_analysis_type(): void
    {
        Http::fake(['api.openai.com/*' => fn () => $this->answer()]);
        $admin = $this->admin();
        $this->actingAs($admin, 'admin');
        $product = Product::create(['brand' => 'Original', 'name' => 'Original']);
        $data = ['brand' => 'Original', 'name' => 'Original', 'category' => 'complementary', 'ingredients' => [['label' => 'Keep this ingredient']], 'nutrients' => []];
        $old = FeedCatalog::productDraft($product, $data, $admin->id);
        $this->post("/admin/feed-scanner/versions/{$old->id}/attachments", ['purpose' => 'analysis', 'file' => UploadedFile::fake()->image('nutrition.jpg')])->assertRedirect();
        $version = FeedCatalog::latestProduct($product);
        $this->assertNotSame($old->id, $version->id);
        $this->assertSame($data['ingredients'], $version->data['ingredients']);
        $this->assertSame('4.2', $version->data['nutrients'][0]['value']);
        $this->assertEquals($data, $old->fresh()->data);
        $this->assertSame('completed', $version->attachments()->sole()->luna['status']);
        $this->assertSame('draft', $version->status);
    }

    public function test_admin_prefill_preserves_both_readings_as_private_evidence_when_the_product_is_saved(): void
    {
        Http::fake(['api.openai.com/*' => fn () => $this->answer()]);
        $this->actingAs($this->admin(), 'admin');
        $result = $this->post('/admin/feed-scanner/products/prefill', ['photos' => [UploadedFile::fake()->image('label.jpg')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('analyses.0.luna.status', 'completed')->assertJsonPath('analyses.0.ocr.engine', 'test-ocr')->json();
        $this->assertSame(0, Product::count());
        $this->post('/admin/feed-scanner/products', $result['data'] + ['analysis_attachment_ids' => $result['analysis_attachment_ids']])->assertSessionHasNoErrors()->assertRedirect();
        $version = ProductVersion::sole();
        $this->assertSame($result['analysis_attachment_ids'][0], $version->attachments()->sole()->id);
        $this->actingAs($this->admin(), 'admin');
        $this->post('/admin/feed-scanner/products', $result['data'] + ['analysis_attachment_ids' => $result['analysis_attachment_ids']])->assertNotFound();
        $this->assertSame(1, Product::count());
    }

    public function test_admin_prefill_without_luna_configuration_still_preserves_ocr_for_manual_entry(): void
    {
        config(['feed-scanner.api_key' => null]);
        $this->actingAs($this->admin(), 'admin');
        $result = $this->post('/admin/feed-scanner/products/prefill', ['photos' => [UploadedFile::fake()->image('label.jpg')]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('analyses.0.ocr.status', 'completed')
            ->assertJsonPath('analyses.0.luna.error_code', 'reader_not_configured')->json();
        $this->post('/admin/feed-scanner/products', ['name' => 'Manual product', 'brand' => 'Manual brand', 'category' => 'complementary',
            'ingredients' => [], 'nutrients' => [], 'analysis_attachment_ids' => $result['analysis_attachment_ids']])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('test-ocr', ProductVersion::sole()->attachments()->sole()->ocr['engine']);
        Http::assertNothingSent();
    }

    public function test_admin_correction_during_luna_analysis_keeps_precedence(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin');
        $product = Product::create(['brand' => 'Original', 'name' => 'Original']);
        $data = ['brand' => 'Original', 'name' => 'Original', 'category' => 'complementary', 'ingredients' => [], 'nutrients' => []];
        $old = FeedCatalog::productDraft($product, $data, $admin->id);
        Http::fake(['api.openai.com/*' => function () use ($product, $data, $admin, $old) {
            FeedCatalog::productDraft($product, array_replace($data, ['name' => 'Reviewer correction']), $admin->id, sourceVersion: $old);

            return $this->answer();
        }]);
        $this->post("/admin/feed-scanner/versions/{$old->id}/attachments", ['purpose' => 'analysis', 'file' => UploadedFile::fake()->image('nutrition.jpg')])->assertRedirect();
        $latest = FeedCatalog::latestProduct($product);
        $this->assertSame('Reviewer correction', $latest->data['name']);
        $this->assertSame([], $latest->data['nutrients']);
        $this->assertSame(2, ProductVersion::where('product_id', $product->id)->count());
        $this->assertSame('completed', $latest->attachments()->sole()->luna['status']);
    }

    public function test_overlapping_prefill_photos_merge_equivalent_facts_and_can_be_saved(): void
    {
        $first = $this->raw();
        $second = $this->raw();
        $second['ingredients'][0]['label'] = 'luzerne';
        $second['ingredients'][0]['evidence'] = 'Samenstelling: luzerne 80%';
        $second['nutrients'][0]['value'] = '4.20';
        $second['nutrients'][0]['source'] = 'Per kg product: suiker 4,20%';
        $answers = [$first, $second];
        Http::fake(['api.openai.com/*' => function () use (&$answers) {
            return $this->answer(array_shift($answers));
        }]);
        $this->actingAs($this->admin(), 'admin');
        $result = $this->post('/admin/feed-scanner/products/prefill', ['photos' => [UploadedFile::fake()->image('overview.jpg'), UploadedFile::fake()->image('crop.jpg')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(1, 'data.ingredients')->assertJsonCount(1, 'data.nutrients')->assertJsonCount(2, 'analyses')->json();
        $this->assertStringContainsString($first['nutrients'][0]['source'], $result['data']['nutrients'][0]['source']);
        $this->assertStringContainsString($second['nutrients'][0]['source'], $result['data']['nutrients'][0]['source']);
        $this->postJson('/admin/feed-scanner/products', $result['data'] + ['analysis_attachment_ids' => $result['analysis_attachment_ids']])->assertRedirect();
        $version = ProductVersion::sole();
        $this->assertCount(1, $version->data['ingredients']);
        $this->assertCount(1, $version->data['nutrients']);
        $this->assertSame(2, $version->attachments()->count());
    }

    public static function ingredientValueConfidence(): array
    {
        $cases = [];
        foreach (['amount' => '80%', 'added_selenium' => 'confirmed', 'selenium_form' => 'organic'] as $field => $value) {
            foreach ([false, true] as $corroborated) {
                foreach ([false, true] as $reversed) {
                    $cases[$field.'-'.($corroborated ? 'corroborated' : 'uncertain').'-'.($reversed ? 'reversed' : 'original')] = [$field, $value, $corroborated, $reversed];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('ingredientValueConfidence')]
    public function test_prefill_warns_when_an_ingredient_value_has_only_uncertain_support(string $field, string $value, bool $corroborated, bool $reversed): void
    {
        $clear = $this->raw();
        $clear['ingredients'][0][$field] = $corroborated ? $value : ($field === 'amount' ? null : 'unknown');
        $uncertain = $clear;
        $uncertain['ingredients'][0][$field] = $value;
        $uncertain['ingredients'][0]['confidence'] = 'low';
        $uncertain['ingredients'][0]['evidence'] = 'Onduidelijke etiketregel';
        $answers = $reversed ? [$uncertain, $clear] : [$clear, $uncertain];
        Http::fake(['api.openai.com/*' => function () use (&$answers) {
            return $this->answer(array_shift($answers));
        }]);
        $this->actingAs($this->admin(), 'admin');
        $result = $this->post('/admin/feed-scanner/products/prefill', ['photos' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(1, 'data.ingredients')->assertJsonPath('data.ingredients.0.'.$field, $value)->json();

        $warning = 'Ingrediënt 1 (Luzerne): niet zeker gelezen.';
        if ($corroborated) {
            $this->assertNotContains($warning, $result['notes']);
        } else {
            $this->assertContains($warning, $result['notes']);
        }
    }

    public function test_conflicting_prefill_values_require_review_and_preserve_each_reading(): void
    {
        $first = $this->raw();
        $second = $this->raw();
        $second['nutrients'][0]['value'] = '14.2';
        $second['ingredients'][0]['amount'] = '60%';
        $third = $this->raw(); // Repeating one value must not hide the conflict.
        $third['nutrients'][] = array_replace($third['nutrients'][0], ['basis' => 'dry_matter', 'value' => '5']);
        $answers = [$first, $second, $third];
        Http::fake(['api.openai.com/*' => function () use (&$answers) {
            return $this->answer(array_shift($answers));
        }]);
        $this->actingAs($this->admin(), 'admin');
        $result = $this->post('/admin/feed-scanner/products/prefill', ['photos' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg'), UploadedFile::fake()->image('three.jpg')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(1, 'data.nutrients')->assertJsonPath('data.nutrients.0.basis', 'dry_matter')->json();
        $this->assertSame('', $result['data']['ingredients'][0]['amount']);
        $this->assertStringContainsString('tegenstrijdige', implode(' ', $result['notes']));
        $this->assertSame('4.2', $result['analyses'][0]['luna']['data']['nutrients'][0]['value']);
        $this->assertSame('14.2', $result['analyses'][1]['luna']['data']['nutrients'][0]['value']);
        $this->postJson('/admin/feed-scanner/products', $result['data'] + ['analysis_attachment_ids' => $result['analysis_attachment_ids']])->assertRedirect();
    }

    public function test_multiple_prefill_rounds_can_attach_more_than_four_photos_to_one_product(): void
    {
        Http::fake(['api.openai.com/*' => fn () => $this->answer()]);
        $this->actingAs($this->admin(), 'admin');
        $ids = [];
        for ($round = 0; $round < 2; $round++) {
            $result = $this->post('/admin/feed-scanner/products/prefill', ['photos' => [UploadedFile::fake()->image('composition.jpg'), UploadedFile::fake()->image('analysis.jpg'), UploadedFile::fake()->image('front.jpg')]], ['Accept' => 'application/json'])->assertOk()->json();
            $ids = [...$ids, ...$result['analysis_attachment_ids']];
            $this->assertSame($result['analysis_attachment_ids'], array_column($result['analyses'], 'id'));
        }
        $this->postJson('/admin/feed-scanner/products', $result['data'] + ['analysis_attachment_ids' => $ids])->assertRedirect();
        $this->assertSame(6, ProductVersion::sole()->attachments()->count());
    }

    private function adminPhoto(ProductVersion $version, AdminUser $admin, string $purpose): FeedLabelAttachment
    {
        $file = UploadedFile::fake()->image($purpose.'.jpg');
        $path = $file->store('feed-labels', 'local');

        return FeedLabelAttachment::create(['product_version_id' => $version->id, 'purpose' => $purpose, 'disk' => 'local', 'path' => $path,
            'original_name' => $purpose.'.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()), 'uploaded_by' => $admin->id]);
    }

    public static function lastCompletingPhoto(): array
    {
        return [['composition'], ['analysis'], ['packaging']];
    }

    #[DataProvider('lastCompletingPhoto')]
    public function test_concurrent_admin_photos_merge_automatic_drafts_in_any_completion_order(string $lastPurpose): void
    {
        $admin = $this->admin();
        $product = Product::create(['brand' => 'Original', 'name' => 'Original']);
        $data = ['brand' => 'Original', 'name' => 'Original', 'category' => 'complementary', 'ingredients' => [], 'nutrients' => []];
        $source = FeedCatalog::productDraft($product, $data, $admin->id);
        $photos = collect(['composition', 'analysis', 'packaging'])->mapWithKeys(fn ($purpose) => [$purpose => $this->adminPhoto($source, $admin, $purpose)]);
        $nested = false;
        Http::fake(['api.openai.com/*' => function () use (&$nested, $photos, $lastPurpose) {
            if (! $nested) {
                $nested = true;
                foreach ($photos->except($lastPurpose) as $photo) {
                    app(FeedPhotoAnalysis::class)->attachment($photo);
                }
            }

            return $this->answer();
        }]);
        app(FeedPhotoAnalysis::class)->attachment($photos[$lastPurpose]);
        $latest = FeedCatalog::latestProduct($product);
        $this->assertSame('Luzerne', $latest->data['ingredients'][0]['label']);
        $this->assertSame('4.2', $latest->data['nutrients'][0]['value']);
        $this->assertSame('Luna product', $latest->data['name']);
        $this->assertSame(4, $latest->number);
        $this->assertNotNull($latest->photo_analysis_parent_id);
        $this->assertEquals($data, $source->fresh()->data);
        $this->assertCount(3, $latest->attachments);
        foreach ($latest->attachments as $copy) {
            $this->assertSame('completed', $copy->ocr['status']);
            $this->assertSame('completed', $copy->luna['status']);
        }
    }

    public function test_manual_correction_between_automatic_drafts_stops_later_extraction(): void
    {
        $admin = $this->admin();
        $product = Product::create(['brand' => 'Original', 'name' => 'Original']);
        $data = ['brand' => 'Original', 'name' => 'Original', 'category' => 'complementary', 'ingredients' => [], 'nutrients' => []];
        $source = FeedCatalog::productDraft($product, $data, $admin->id);
        $composition = $this->adminPhoto($source, $admin, 'composition');
        $nutrition = $this->adminPhoto($source, $admin, 'analysis');
        $nested = false;
        Http::fake(['api.openai.com/*' => function () use (&$nested, $composition, $product, $admin) {
            if (! $nested) {
                $nested = true;
                app(FeedPhotoAnalysis::class)->attachment($composition);
                $automatic = FeedCatalog::latestProduct($product);
                FeedCatalog::productDraft($product, array_replace($automatic->data, ['name' => 'Manual correction']), $admin->id, sourceVersion: $automatic);
            }

            return $this->answer();
        }]);
        app(FeedPhotoAnalysis::class)->attachment($nutrition);
        $latest = FeedCatalog::latestProduct($product);
        $this->assertSame('Manual correction', $latest->data['name']);
        $this->assertSame([], $latest->data['nutrients']);
        $this->assertSame(3, $latest->number);
        $this->assertNull($latest->photo_analysis_parent_id);
        $this->assertSame('completed', $latest->attachments()->where('purpose', 'analysis')->sole()->luna['status']);
    }

    public function test_approval_during_analysis_does_not_create_an_automatic_correction(): void
    {
        $admin = $this->admin();
        $product = Product::create(['brand' => 'Original', 'name' => 'Original']);
        $data = ['brand' => 'Original', 'name' => 'Original', 'category' => 'complementary', 'ingredients' => [], 'nutrients' => []];
        $source = FeedCatalog::productDraft($product, $data, $admin->id);
        $photo = $this->adminPhoto($source, $admin, 'analysis');
        Http::fake(['api.openai.com/*' => function () use ($source, $product) {
            $source->update(['status' => 'approved']);
            $product->forceFill(['catalog_status' => 'published', 'published_version_id' => $source->id])->save();

            return $this->answer();
        }]);
        app(FeedPhotoAnalysis::class)->attachment($photo);
        $this->assertSame(1, ProductVersion::where('product_id', $product->id)->count());
        $this->assertEquals($data, $source->fresh()->data);
        $this->assertSame('approved', $source->fresh()->status);
        $this->assertSame('completed', $photo->fresh()->luna['status']);
    }

    public function test_conflicting_concurrent_photos_of_the_same_type_do_not_overwrite_each_other(): void
    {
        $admin = $this->admin();
        $product = Product::create(['brand' => 'Original', 'name' => 'Original']);
        $source = FeedCatalog::productDraft($product, ['brand' => 'Original', 'name' => 'Original', 'category' => 'complementary', 'ingredients' => [], 'nutrients' => []], $admin->id);
        $slow = $this->adminPhoto($source, $admin, 'analysis');
        $fast = $this->adminPhoto($source, $admin, 'analysis');
        $nested = false;
        Http::fake(['api.openai.com/*' => function () use (&$nested, $fast) {
            if (! $nested) {
                $nested = true;
                app(FeedPhotoAnalysis::class)->attachment($fast);
                $raw = $this->raw();
                $raw['nutrients'][0]['value'] = '14.2';

                return $this->answer($raw);
            }

            return $this->answer();
        }]);
        app(FeedPhotoAnalysis::class)->attachment($slow);
        $latest = FeedCatalog::latestProduct($product);
        $this->assertSame(2, $latest->number);
        $this->assertSame('4.2', $latest->data['nutrients'][0]['value']);
        $this->assertSame('14.2', $latest->attachments()->where('path', $slow->path)->sole()->luna['data']['nutrients'][0]['value']);
    }
}
