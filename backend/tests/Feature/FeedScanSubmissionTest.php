<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\FeedRuleVersion;
use App\Models\FeedScanSubmission;
use App\Models\Ingredient;
use App\Models\IngredientRevision;
use App\Models\Product;
use App\Models\ScanResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeedScanSubmissionTest extends TestCase
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
        $this->asOwner($this->owner);
    }

    private function asOwner(User $user): void
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

    private function createSubmission(bool $photos = true): array
    {
        $client = (string) Str::uuid();
        $s = $this->postJson('/api/feed-scanner/submissions', ['client_id' => $client, 'name' => 'Testvoer'])->assertOk()->json();
        $this->postJson('/api/feed-scanner/submissions', ['client_id' => $client, 'name' => 'Testvoer'])->assertJsonPath('id', $s['id']);
        if ($photos) {
            foreach (['composition', 'analysis'] as $slot) {
                $this->post('/api/feed-scanner/submissions/'.$s['id'].'/photos', ['slot' => $slot, 'file' => UploadedFile::fake()->image('label.jpg')], ['Accept' => 'application/json'])->assertOk();
            }
        }

        return $s;
    }

    private function fakeReader(string $sugar = '6', string $starch = '14', string $basis = 'product'): void
    {
        $data = ['brand' => 'Merk', 'name' => 'Testvoer', 'barcode' => null, 'category' => 'complementary', 'category_confidence' => 'high',
            'composition_text' => 'Timothee', 'composition_confidence' => 'high', 'unreadable_fields' => [],
            'ingredients' => [['label' => 'Timothee', 'amount' => null, 'evidence' => 'Timothee', 'confidence' => 'high', 'selenium_form' => 'unknown', 'added_selenium' => 'unknown']],
            'nutrients' => array_map(fn ($code, $value) => ['code' => $code, 'value' => $value, 'unit' => '%', 'basis' => $basis, 'source' => 'Analyse per kg product', 'confidence' => 'high', 'qualifier' => 'exact'], ['sugar', 'starch'], [$sugar, $starch])];
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($data)]]]]])]);
        $profile = DB::table('feed_assessment_profiles')->where('code', 'standard')->first();
        $rule = FeedRuleVersion::where('profile_id', $profile->id)->orderByDesc('number')->firstOrFail();
        $rule->update(['status' => 'published']);
        DB::table('feed_assessment_profiles')->where('id', $profile->id)->update(['active_rule_version_id' => $rule->id]);
    }

    public function test_uploads_are_private_idempotent_and_owner_scoped(): void
    {
        $s = $this->createSubmission();
        $this->assertSame(1, Product::count());
        $file = UploadedFile::fake()->image('same.jpg');
        $url = '/api/feed-scanner/submissions/'.$s['id'].'/photos';
        $this->post($url, ['slot' => 'composition', 'file' => $file])->assertOk();
        $count = DB::table('feed_label_attachments')->count();
        $this->post($url, ['slot' => 'composition', 'file' => $file])->assertOk();
        $this->assertSame($count, DB::table('feed_label_attachments')->count());
        $attachment = DB::table('feed_label_attachments')->first();
        Storage::disk('local')->assertExists($attachment->path);
        $this->asOwner(User::factory()->create());
        $this->getJson('/api/feed-scanner/submissions/'.$s['id'])->assertNotFound();
        $this->postJson('/api/feed-scanner/submissions/'.$s['id'].'/analyse')->assertNotFound();
    }

    public function test_required_photos_and_missing_key_preserve_review_submission(): void
    {
        $s = $this->createSubmission(false);
        $this->postJson('/api/feed-scanner/submissions/'.$s['id'].'/analyse')->assertUnprocessable();
        $s = $this->createSubmission();
        config(['feed-scanner.api_key' => null]);
        $this->postJson('/api/feed-scanner/submissions/'.$s['id'].'/analyse')->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('error_code', 'reader_not_configured');
        $this->assertDatabaseHas('products', ['id' => $s['product_id'], 'needs_review' => true, 'catalog_status' => 'draft', 'published_version_id' => null]);
        Http::assertNothingSent();
        $this->assertSame(0, ScanResult::count());
    }

    public function test_real_pipeline_uses_published_rules_and_exact_published_knowledge_once(): void
    {
        $card = Ingredient::create(['name' => 'Timothee']);
        $revision = IngredientRevision::create(['ingredient_id' => $card->id, 'number' => 1, 'status' => 'published', 'data' => ['name' => 'Timothee', 'explanation' => 'Gepubliceerde uitleg', 'assessment' => 'preferred']]);
        $card->forceFill(['catalog_status' => 'published', 'published_version_id' => $revision->id])->save();
        $s = $this->createSubmission();
        $this->fakeReader();
        $url = '/api/feed-scanner/submissions/'.$s['id'].'/analyse';
        $response = $this->postJson($url)->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('public_result.score', '0')
            ->assertJsonPath('public_result.ingredients.0.explanation', 'Gepubliceerde uitleg');
        $this->postJson($url)->assertOk()->assertJsonPath('scan_result_id', $response->json('scan_result_id'));
        Http::assertSentCount(1);
        $this->assertSame(1, ScanResult::count());
        $this->assertDatabaseHas('products', ['id' => $s['product_id'], 'needs_review' => true, 'published_version_id' => null]);
        $this->getJson('/api/feed-scanner/scans/'.$response->json('scan_result_id'))->assertOk()->assertJsonPath('result.provisional', true);
    }

    public function test_unknown_basis_does_not_trigger_zero_and_related_terms_are_not_confirmed(): void
    {
        $card = Ingredient::create(['name' => 'Ander gras']);
        $revision = IngredientRevision::create(['ingredient_id' => $card->id, 'number' => 1, 'status' => 'published', 'data' => ['name' => 'Ander gras', 'terms' => [['term' => 'Timothee', 'kind' => 'related']]]]);
        $card->forceFill(['catalog_status' => 'published', 'published_version_id' => $revision->id])->save();
        $s = $this->createSubmission();
        $this->fakeReader('6', '14', 'unknown');
        $this->postJson('/api/feed-scanner/submissions/'.$s['id'].'/analyse')->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('public_result.score', null)->assertJsonPath('public_result.score_cap', null)->assertJsonPath('public_result.ingredients.0.match_status', 'unknown');
        $this->assertNull(ScanResult::first()->score);
    }

    public function test_provider_failure_and_processing_lease_are_retry_safe(): void
    {
        $s = $this->createSubmission();
        Http::fake(['*' => Http::response([], 503)]);
        $this->postJson('/api/feed-scanner/submissions/'.$s['id'].'/analyse')->assertJsonPath('status', 'failed')->assertJsonPath('error_code', 'reader_unavailable');
        FeedScanSubmission::find($s['id'])->update(['status' => 'processing', 'processing_started_at' => now(), 'processing_token' => (string) Str::uuid()]);
        $this->postJson('/api/feed-scanner/submissions/'.$s['id'].'/analyse')->assertJsonPath('status', 'processing');
        Http::assertSentCount(1);
    }
}
