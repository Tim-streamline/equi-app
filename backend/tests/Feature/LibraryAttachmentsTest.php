<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\{AdminUser, LibraryItem, Plan, Subscription, User};
use App\Support\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class LibraryAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        $this->actingAs(AdminUser::create(['name' => 'Editor', 'email' => 'pdf@example.test', 'password' => 'password', 'role' => 'content_editor', 'active' => true]), 'admin');
        Storage::fake('local');
    }
    private function customer(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($user->id) {
            public function __construct(private string $id) {}
            public function handle($request, $next) { $request->attributes->set('powersync_user_id', $this->id); return $next($request); }
        });
        return $user;
    }
    private function item(array $data = []): LibraryItem
    {
        return LibraryItem::create($data + ['title' => 'Lesson', 'slug' => (string) Str::uuid(), 'format' => 'video', 'published_at' => now()->subDay(), 'body' => 'Protected lesson']);
    }
    private function pdf(string $name = 'worksheet.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj <</Type /Catalog>> endobj\n%%EOF");
    }
    private function plus(User $user): Subscription
    {
        $plan = Plan::create(['slug' => 'plus', 'label' => 'Plus', 'name' => 'Plus', 'price_cents' => 900, 'interval' => 'monthly']);
        return Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active', 'price_cents' => 900, 'interval' => 'monthly', 'paid_through' => now()->addMonth()]);
    }
    public function test_admin_can_create_replace_reorder_title_and_remove_private_pdfs(): void
    {
        $this->admin();
        $this->post('/admin/library', ['title' => 'With PDFs', 'format' => 'video', 'is_plus' => true, 'credit_cost' => 5,
            'attachments' => [['title' => 'Checklist', 'file' => $this->pdf()], ['title' => 'Summary', 'file' => $this->pdf('summary.pdf')]]])
            ->assertSessionHasNoErrors()->assertRedirect('/admin/library');
        $item = LibraryItem::firstOrFail();
        $this->assertTrue($item->is_plus); $this->assertEquals(0, $item->credit_cost);
        [$first, $second] = $item->attachments;
        Storage::disk('local')->assertExists($first->path);
        $this->get('/admin/library/attachments/'.$first->id)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // Method-spoofed POST is the production multipart update path.
        $this->post('/admin/library/'.$item->id, ['_method' => 'put', 'title' => 'With PDFs', 'format' => 'article', 'is_plus' => false, 'credit_cost' => 3,
            'attachments' => [['id' => $second->id, 'title' => 'Renamed'], ['id' => $first->id, 'title' => 'Replacement', 'file' => $this->pdf('replacement.pdf')]]])
            ->assertSessionHasNoErrors();
        $this->assertEquals([$second->id, $first->id], $item->attachments()->pluck('id')->all());
        $this->assertFalse($item->fresh()->is_plus); $this->assertEquals(3, $item->fresh()->credit_cost);
        Storage::disk('local')->assertMissing($first->path);
        Storage::disk('local')->assertExists($first->fresh()->path);
        $this->assertSame('Renamed', $second->fresh()->title);
        $paths = $item->attachments()->pluck('path')->all();
        $this->post('/admin/library/'.$item->id, ['_method' => 'put', 'title' => 'With PDFs', 'format' => 'article', 'attachments_present' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('library_attachments', 0);
        Storage::disk('local')->assertMissing($paths);
    }
    public function test_validation_rejects_foreign_ids_missing_files_and_non_pdf_uploads_without_partial_changes(): void
    {
        $this->admin(); $item = $this->item(); $other = $this->item();
        $foreign = $other->attachments()->create(['title' => 'Foreign', 'name' => 'file.pdf', 'path' => 'private.pdf', 'size_bytes' => 20]);
        $url = '/admin/library/'.$item->id; $base = ['title' => 'Changed', 'format' => 'video'];
        $this->put($url, $base + ['attachments' => [['id' => $foreign->id, 'title' => 'Wrong']]])->assertSessionHasErrors('attachments.0.id');
        $this->put($url, $base + ['attachments' => [['title' => 'Missing']]])->assertSessionHasErrors('attachments.0.file');
        $this->post($url, $base + ['_method' => 'put', 'attachments' => [['title' => 'Invalid', 'file' => UploadedFile::fake()->image('image.jpg')]]])->assertSessionHasErrors('attachments.0.file');
        $this->assertSame('Lesson', $item->fresh()->title); $this->assertDatabaseCount('library_attachments', 1);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
    public function test_plus_access_never_spends_credits_and_prior_permanent_unlocks_survive(): void
    {
        $user = $this->customer(); $ledger = app(CreditLedger::class); $ledger->grant($user, 10, 'adjustment');
        $item = $this->item(['is_plus' => true, 'credit_cost' => 0]); $url = '/api/library/'.$item->id;
        $this->getJson($url)->assertJsonPath('canRead', false)->assertJsonPath('item.isPlus', true)->assertJsonPath('body', null);
        $this->postJson($url.'/unlock', ['credits' => 0])->assertForbidden();
        $subscription = $this->plus($user);
        $this->getJson($url)->assertJsonPath('canRead', true)->assertJsonPath('body', 'Protected lesson');
        $this->postJson($url.'/unlock', ['credits' => 0])->assertOk();
        $this->assertSame(10, $ledger->summary($user)['balance']); $this->assertDatabaseCount('library_unlocks', 0);
        $subscription->update(['ended_at' => now()]);
        $this->getJson($url)->assertJsonPath('canRead', false);
        DB::table('library_unlocks')->insert(['user_id' => $user->id, 'item_id' => $item->id]);
        $this->getJson($url)->assertJsonPath('canRead', true);
        $normal = $this->item(['credit_cost' => 2]);
        $subscription->update(['ended_at' => null]);
        $this->getJson('/api/library/'.$normal->id)->assertJsonPath('canRead', false);
    }
    public function test_pdf_requires_parent_access_and_signed_link_rechecks_revoked_access(): void
    {
        Storage::fake('local'); $user = $this->customer(); $item = $this->item(['is_plus' => true]);
        Storage::disk('local')->put('lesson.pdf', '%PDF-1.4 private');
        $file = $item->attachments()->create(['title' => 'Checklist', 'name' => 'lesson.pdf', 'path' => 'lesson.pdf', 'size_bytes' => 16]);
        $base = '/api/library/'.$item->id; $open = $base.'/attachments/'.$file->id.'/open';
        $this->getJson($base)->assertJsonPath('attachments', []);
        $this->postJson($open)->assertForbidden();
        $this->get('/api/library-attachments/'.$file->id.'?user='.$user->id)->assertForbidden();
        $subscription = $this->plus($user);
        $this->getJson($base)->assertJsonPath('attachments.0.title', 'Checklist')->assertJsonMissingPath('attachments.0.path');
        $link = $this->postJson($open)->assertOk()->json('url');
        $this->get($link)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $subscription->update(['paid_through' => now()->subMinute()]); $this->get($link)->assertForbidden();
        DB::table('library_unlocks')->insert(['user_id' => $user->id, 'item_id' => $item->id]);
        $this->get($link)->assertOk();
        $item->update(['published_at' => null]); $this->get($link)->assertNotFound();
        $item->update(['published_at' => now()]); $user->update(['disabled_at' => now()]); $this->get($link)->assertForbidden();
        $user->update(['disabled_at' => null]); $this->travel(3)->minutes(); $this->get($link)->assertForbidden();
        $other = $this->item(); $this->postJson('/api/library/'.$other->id.'/attachments/'.$file->id.'/open')->assertNotFound();
        $this->customer(); $this->postJson($open)->assertForbidden();
    }
    public function test_deleting_item_removes_private_files(): void
    {
        $this->admin(); $item = $this->item(); Storage::disk('local')->put('lesson.pdf', '%PDF private');
        $item->attachments()->create(['title' => 'File', 'name' => 'lesson.pdf', 'path' => 'lesson.pdf', 'size_bytes' => 12]);
        $this->delete('/admin/library/'.$item->id)->assertRedirect();
        $this->assertDatabaseCount('library_attachments', 0); Storage::disk('local')->assertMissing('lesson.pdf');
    }
}
