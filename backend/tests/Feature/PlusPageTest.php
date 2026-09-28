<?php
namespace Tests\Feature;
use App\Models\{AdminUser, PlusPage};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
class PlusPageTest extends TestCase
{
    use RefreshDatabase;
    private function editor(string $role = 'content_editor'): void
    {
        $this->actingAs(AdminUser::create(['name' => 'Editor', 'email' => 'plus@example.test', 'password' => 'password', 'role' => $role, 'active' => true]), 'admin');
    }
    public function test_default_page_and_photo_need_no_horse_or_subscription(): void
    {
        $this->getJson('/api/plus-page')->assertOk()->assertJsonPath('content.price', '€ 147,00')->assertJsonPath('content.reviewsAreExamples', true)->assertJsonCount(4, 'content.faqs')->assertJsonPath('portraitImageUrl', null);
        $this->get('/api/plus-page/images/hero')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/api/plus-page/images/portrait')->assertNotFound();
        $this->get('/api/plus-page/images/unknown')->assertNotFound();
        $this->assertDatabaseCount('subscriptions', 0); $this->assertDatabaseCount('plus_pages', 0);
    }
    public function test_editor_can_publish_text_photos_and_empty_lists_without_touching_plans(): void
    {
        Storage::fake('local'); $this->editor();
        $this->get('/admin/plus-page')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('PlusPage/Index')->has('content.steps', 4));
        $content = PlusPage::defaults(); $content['heroTitle'] = 'Aangepaste kop';
        $content['reviews'] = []; $content['faqs'] = []; $content['benefits'] = [];
        $this->post('/admin/plus-page', ['content' => json_encode($content), 'hero' => UploadedFile::fake()->image('hero.jpg'), 'portrait' => UploadedFile::fake()->image('shelley.png')])->assertRedirect()->assertSessionHasNoErrors();
        $page = PlusPage::current(); Storage::disk('local')->assertExists([$page->hero_path, $page->portrait_path]);
        $this->getJson('/api/plus-page')->assertJsonPath('content.heroTitle', 'Aangepaste kop')->assertJsonCount(0, 'content.reviews');
        $this->get('/api/plus-page/images/portrait')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertDatabaseCount('subscriptions', 0); $this->assertDatabaseCount('plans', 0);
        $oldHero = $page->hero_path; $oldPortrait = $page->portrait_path;
        $this->post('/admin/plus-page', ['content' => json_encode($content), 'hero' => UploadedFile::fake()->image('replacement.jpg'), 'remove_portrait' => '1'])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing([$oldHero, $oldPortrait]);
        $this->getJson('/api/plus-page')->assertJsonPath('portraitImageUrl', null);
        $this->assertDatabaseHas('audit_logs', ['target_type' => 'PlusPage', 'target_id' => PlusPage::PAGE_ID, 'action' => 'updated']);
    }
    public function test_invalid_text_or_non_image_upload_does_not_change_page(): void
    {
        Storage::fake('local'); $this->editor();
        $this->postJson('/admin/plus-page', ['content' => ['heroTitle' => 'Incomplete']])->assertUnprocessable();
        $this->post('/admin/plus-page', ['content' => json_encode(PlusPage::defaults()), 'hero' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('hero');
        $this->assertDatabaseCount('plus_pages', 0); $this->assertSame([], Storage::disk('local')->allFiles());
    }
    public function test_content_management_requires_editor_role(): void
    {
        $this->get('/admin/plus-page')->assertRedirect('/admin/login'); $this->editor('support');
        $this->get('/admin/plus-page')->assertForbidden();
        $this->post('/admin/plus-page', ['content' => PlusPage::defaults()])->assertForbidden();
    }
}
