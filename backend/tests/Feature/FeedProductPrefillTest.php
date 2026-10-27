<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Ingredient;
use App\Models\IngredientRevision;
use App\Models\Product;
use App\Models\ProductVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FeedProductPrefillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['feed-scanner.api_key' => 'test-key']);
        $admin = AdminUser::create(['name' => 'Feed admin', 'email' => 'feed-admin@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $this->actingAs($admin, 'admin');
    }

    private function fakeReader(array $overrides = []): void
    {
        $data = array_replace([
            'brand' => 'Voorbeeldmerk', 'name' => 'Grasbrok', 'barcode' => null, 'category' => 'complementary', 'category_confidence' => 'high',
            'composition_text' => 'Lijnzaad, biergist', 'composition_confidence' => 'high',
            'ingredients' => [
                ['label' => 'Linseed', 'amount' => '12%', 'evidence' => 'Lijnzaad 12%', 'confidence' => 'high', 'added_selenium' => 'unknown', 'selenium_form' => 'unknown'],
                ['label' => 'Biergist', 'amount' => null, 'evidence' => 'biergist', 'confidence' => 'low', 'added_selenium' => 'unknown', 'selenium_form' => 'unknown'],
            ],
            'nutrients' => [
                ['code' => 'sugar', 'value' => '4.2', 'unit' => '%', 'basis' => 'product', 'qualifier' => 'maximum', 'source' => 'Suiker max 4,2%', 'confidence' => 'high'],
                ['code' => 'protein', 'value' => null, 'unit' => '%', 'basis' => 'unknown', 'qualifier' => 'exact', 'source' => null, 'confidence' => 'low'],
            ],
            'unreadable_fields' => ['ruw as'],
        ], $overrides);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($data)]]]]])]);
    }

    private function publishedCard(string $name, array $terms): Ingredient
    {
        $this->post('/admin/feed-scanner/ingredients', ['name' => $name, 'category' => 'Zaden', 'explanation' => 'Uitleg.', 'assessment' => 'preferred',
            'exceptions' => '', 'default_tag' => null, 'library_item_id' => null, 'internal_notes' => '', 'terms' => $terms])->assertSessionHasNoErrors();
        $ingredient = Ingredient::where('name', $name)->sole();
        $this->post("/admin/feed-scanner/ingredients/{$ingredient->id}/publish", ['version_id' => IngredientRevision::where('ingredient_id', $ingredient->id)->latest('number')->value('id')])->assertSessionHasNoErrors();

        return $ingredient->fresh();
    }

    public function test_pasted_text_fills_the_form_with_suggestions_and_notes_without_saving(): void
    {
        $card = $this->publishedCard('Lijnzaad', [['term' => 'Linseed', 'language' => 'en', 'kind' => 'exact']]);
        $this->fakeReader();

        $response = $this->postJson('/admin/feed-scanner/products/prefill', ['text' => 'Samenstelling: Linseed 12%, biergist'])->assertOk();

        $response->assertJsonPath('data.brand', 'Voorbeeldmerk')->assertJsonPath('data.category', 'complementary')
            ->assertJsonPath('data.ingredients.0.ingredient_id', $card->id)->assertJsonPath('data.ingredients.0.match_status', 'suggested')
            ->assertJsonPath('data.ingredients.1.ingredient_id', '')->assertJsonPath('data.ingredients.1.match_status', 'unknown')
            ->assertJsonCount(1, 'data.nutrients')->assertJsonPath('data.nutrients.0.qualifier', 'maximum');
        $this->assertArrayNotHasKey('barcode', $response->json('data'));
        $notes = implode("\n", $response->json('notes'));
        $this->assertStringContainsString('Ingrediënt 2 (Biergist): niet zeker gelezen', $notes);
        $this->assertStringContainsString('geen kenniskaart gevonden', $notes);
        $this->assertStringContainsString('Niet leesbaar: ruw as', $notes);
        $this->assertSame(0, Product::count());
        $this->assertSame(0, ProductVersion::count());
        Http::assertSent(fn (Request $request) => str_contains(json_encode($request->data()), 'Linseed 12%') && $request['store'] === false);
    }

    public function test_photos_are_sent_and_unknown_category_asks_for_a_choice(): void
    {
        $this->fakeReader(['category' => 'unknown', 'category_confidence' => 'low']);

        $response = $this->post('/admin/feed-scanner/products/prefill', ['photos' => [UploadedFile::fake()->createWithContent('etiket.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='))]], ['Accept' => 'application/json'])->assertOk();

        $this->assertArrayNotHasKey('category', $response->json('data'));
        $this->assertContains('Categorie niet herkend: kies zelf de categorie.', $response->json('notes'));
        Http::assertSent(fn (Request $request) => str_contains(json_encode($request->data(), JSON_UNESCAPED_SLASHES), 'data:image/png;base64,'));
    }

    public function test_requires_input_configuration_and_a_feed_role(): void
    {
        Http::fake();
        $this->postJson('/admin/feed-scanner/products/prefill', [])->assertUnprocessable()->assertJsonValidationErrors(['text', 'photos']);
        $this->postJson('/admin/feed-scanner/products/prefill', ['photos' => [UploadedFile::fake()->create('etiket.pdf', 10, 'application/pdf')]])->assertJsonValidationErrors('photos.0');

        config(['feed-scanner.api_key' => null]);
        $this->postJson('/admin/feed-scanner/products/prefill', ['text' => 'Lijnzaad'])->assertStatus(503);

        config(['feed-scanner.api_key' => 'test-key']);
        $viewer = AdminUser::create(['name' => 'Kijker', 'email' => 'viewer@example.com', 'password' => 'password', 'role' => 'viewer', 'active' => true]);
        $this->actingAs($viewer, 'admin')->postJson('/admin/feed-scanner/products/prefill', ['text' => 'Lijnzaad'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_reader_failure_gives_a_clear_message(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([], 500)]);
        $this->postJson('/admin/feed-scanner/products/prefill', ['text' => 'Lijnzaad'])->assertStatus(422)->assertJsonPath('message', 'Het etiket kon niet worden uitgelezen. Probeer een scherpere foto of plak de tekst.');
    }
}
