<?php
namespace Tests\Feature;
use App\Models\{AdminUser, LibraryItem, PlusPage};
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
        $this->getJson('/api/plus-page')->assertOk()->assertJsonPath('content.price', '€ 147')->assertJsonPath('content.pageTitle', 'Begeleiding op maat')->assertJsonPath('content.heroLabel', 'EQUIBOOST | 3 MAANDEN GERICHT AAN DE SLAG')->assertJsonPath('content.reviewsAreExamples', true)->assertJsonCount(4, 'content.faqs')->assertJsonPath('portraitImageUrl', null);
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
        $content['pageTitle'] = 'Mijn begeleiding'; $content['heroLabel'] = 'Mijn traject';
        $content['period'] = 'Mijn eerste prijsomschrijving'; $content['pricePeriod'] = 'Mijn tweede prijsomschrijving';
        $content['closingLabel'] = 'Eigen afsluitend label'; $content['closingTitle'] = 'Eigen afsluitende kop';
        $content['closingBody'] = 'Eigen afsluitende tekst'; $content['closingButton'] = 'Eigen afsluitende knop';
        $content['reviews'] = []; $content['faqs'] = []; $content['benefits'] = [];
        $this->post('/admin/plus-page', ['protocol_price_cents' => 19900, 'content' => json_encode($content), 'hero' => UploadedFile::fake()->image('hero.jpg'), 'portrait' => UploadedFile::fake()->image('shelley.png')])->assertRedirect()->assertSessionHasNoErrors();
        $page = PlusPage::current(); Storage::disk('local')->assertExists([$page->hero_path, $page->portrait_path]);
        $this->getJson('/api/protocol-page')->assertOk()
            ->assertJsonPath('content.closingLabel', 'Eigen afsluitend label')
            ->assertJsonPath('content.closingTitle', 'Eigen afsluitende kop')
            ->assertJsonPath('content.closingBody', 'Eigen afsluitende tekst')
            ->assertJsonPath('content.closingButton', 'Eigen afsluitende knop');
        $this->getJson('/api/plus-page')->assertJsonPath('protocolPriceCents', 19900)->assertJsonPath('content.price', '€ 199')->assertJsonPath('content.heroTitle', 'Aangepaste kop')->assertJsonPath('content.pageTitle', 'Mijn begeleiding')->assertJsonPath('content.heroLabel', 'Mijn traject')->assertJsonPath('content.period', 'Mijn eerste prijsomschrijving')->assertJsonPath('content.pricePeriod', 'Mijn tweede prijsomschrijving')->assertJsonCount(0, 'content.reviews');
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
        foreach ([0, -1, 1.5, 10000001] as $price) {
            $this->postJson('/admin/plus-page', ['protocol_price_cents' => $price, 'content' => PlusPage::defaults()])->assertUnprocessable()->assertJsonValidationErrors('protocol_price_cents');
        }
        $this->assertDatabaseCount('plus_pages', 0); $this->assertSame([], Storage::disk('local')->allFiles());
    }
    public function test_older_saved_content_gets_new_fields_and_keeps_fractional_prices(): void
    {
        $content = PlusPage::defaults();
        unset($content['pageTitle'], $content['heroLabel'], $content['pricePeriod']);
        $content['heroTitle'] = 'Bestaande eigen tekst';
        PlusPage::create(['id' => PlusPage::PAGE_ID, 'content' => $content, 'protocol_price_cents' => 14750]);
        $this->getJson('/api/plus-page')->assertOk()
            ->assertJsonPath('content.pageTitle', 'Begeleiding op maat')
            ->assertJsonPath('content.heroTitle', 'Bestaande eigen tekst')
            ->assertJsonPath('content.pricePeriod', 'Traject op maat gedurende 3 maanden')
            ->assertJsonPath('content.price', '€ 147,50');
    }
    public function test_equiboost_faq_links_the_published_library_video_with_that_title(): void
    {
        $this->getJson('/api/protocol-page')->assertOk()->assertJsonCount(count(PlusPage::defaults()['memberFaqs']), 'content.memberFaqs')
            ->assertJsonPath('content.memberFaqVideoTitle', 'Veelgestelde vragen tijdens EquiBoost')->assertJsonPath('memberFaqVideo', null);
        LibraryItem::create(['title' => 'Veelgestelde vragen tijdens EquiBoost', 'slug' => 'faq-concept', 'format' => 'video']);
        LibraryItem::create(['title' => 'Veelgestelde vragen tijdens EquiBoost', 'slug' => 'faq-later', 'format' => 'video', 'published_at' => now()->addDay()]);
        $this->getJson('/api/protocol-page')->assertJsonPath('memberFaqVideo', null);
        $video = LibraryItem::create(['title' => 'Veelgestelde vragen tijdens EquiBoost ', 'slug' => 'faq', 'format' => 'video', 'published_at' => now()->subDay()]);
        $this->getJson('/api/protocol-page')->assertJsonPath('memberFaqVideo.id', $video->id)->assertJsonPath('memberFaqVideo.format', 'video');

        $this->editor();
        $content = PlusPage::defaults(); $content['memberFaqVideoTitle'] = ''; $content['memberFaqs'] = [['question' => 'Eigen vraag?', 'answer' => 'Eigen antwoord.']];
        $this->post('/admin/plus-page', ['content' => json_encode($content)])->assertSessionHasNoErrors();
        $this->getJson('/api/protocol-page')->assertJsonPath('memberFaqVideo', null)->assertJsonPath('content.memberFaqs.0.question', 'Eigen vraag?');
        $content['memberFaqs'] = [['question' => '', 'answer' => 'Zonder vraag']];
        $this->post('/admin/plus-page', ['content' => json_encode($content)])->assertSessionHasErrors('content.memberFaqs.0.question');
    }
    public function test_a_page_saved_before_the_equiboost_faq_keeps_its_own_questions_and_can_be_saved_again(): void
    {
        // Like staging: the editor rewrote the sales Q&A before the EquiBoost FAQ existed.
        $content = PlusPage::defaults();
        unset($content['memberFaqTitle'], $content['memberFaqBody'], $content['memberFaqVideoTitle'], $content['memberFaqs']);
        $content['faqs'] = [['question' => 'Zijn er naast de prijs nog andere kosten?', 'answer' => 'Eigen antwoord.'], ['question' => 'Tweede eigen vraag?', 'answer' => 'Ook eigen.']];
        PlusPage::create(['id' => PlusPage::PAGE_ID, 'content' => $content]);

        $page = $this->getJson('/api/protocol-page')->assertOk()->assertJsonCount(2, 'content.faqs')->assertJsonCount(count(PlusPage::defaults()['memberFaqs']), 'content.memberFaqs')
            ->assertJsonPath('content.faqs.0.question', 'Zijn er naast de prijs nog andere kosten?')->json();

        // The admin form sends back what it loaded, now including the EquiBoost FAQ.
        $this->editor();
        $this->post('/admin/plus-page', ['content' => json_encode($page['content'])])->assertSessionHasNoErrors();
        $saved = PlusPage::current()->content;
        $this->assertSame($content['faqs'], $saved['faqs']);
        $this->assertCount(count(PlusPage::defaults()['memberFaqs']), $saved['memberFaqs']);
    }
    public function test_content_management_requires_editor_role(): void
    {
        $this->get('/admin/plus-page')->assertRedirect('/admin/login'); $this->editor('support');
        $this->get('/admin/plus-page')->assertForbidden();
        $this->post('/admin/plus-page', ['content' => PlusPage::defaults()])->assertForbidden();
    }
}
