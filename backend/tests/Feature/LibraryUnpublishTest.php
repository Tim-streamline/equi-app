<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\{AdminUser, LibraryCategory, LibraryItem, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Storage};
use Tests\TestCase;

class LibraryUnpublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpublish_preserves_content_and_unlocks_revokes_access_and_allows_republication(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $admin = AdminUser::create(['name' => 'Editor', 'email' => 'draft@example.test', 'password' => 'password', 'role' => 'content_editor', 'active' => true]);
        $related = LibraryItem::create(['title' => 'Related', 'slug' => 'related', 'format' => 'article', 'published_at' => now()->subDay()]);
        $item = LibraryItem::create(['title' => 'Lesson', 'slug' => 'lesson', 'format' => 'article', 'body' => 'Saved body', 'description' => 'Saved description', 'credit_cost' => 2, 'is_featured' => true, 'published_at' => now()->subDay(), 'featured_suggestion_ids' => [$related->id]]);
        $category = LibraryCategory::create(['label' => 'Health', 'slug' => 'health']);
        $item->categories()->attach($category);
        $item->chapters()->create(['title' => 'Introduction', 'order' => 1]);
        $file = $item->attachments()->create(['title' => 'Worksheet', 'name' => 'worksheet.pdf', 'path' => 'draft-test.pdf', 'size_bytes' => 4]);
        Storage::disk('local')->put($file->path, '%PDF');
        $item->media()->create(['type' => 'image', 'disk' => 'local', 'path' => 'cover.png', 'url' => '/storage/cover.png', 'original_name' => 'cover.png', 'mime_type' => 'image/png', 'size_bytes' => 5]);
        Storage::disk('local')->put('cover.png', 'image');
        DB::table('library_unlocks')->insert(['user_id' => $user->id, 'item_id' => $item->id]);
        $before = $item->fresh()->except(['published_at', 'updated_at']);
        $relations = $item->load('categories', 'chapters', 'attachments', 'media')->getRelations();

        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($user->id) {
            public function __construct(private string $id) {}
            public function handle($request, $next) { $request->attributes->set('powersync_user_id', $this->id); return $next($request); }
        });
        $url = '/api/library/'.$item->id;
        $this->getJson($url)->assertOk()->assertJsonPath('body', 'Saved body');
        $signed = $this->postJson($url.'/attachments/'.$file->id.'/open')->assertOk()->json('url');
        $this->actingAs($admin, 'admin')->from('/admin/library/'.$item->id.'/edit')
            ->post('/admin/library/'.$item->id.'/unpublish', ['title' => 'Must not overwrite'])
            ->assertRedirect('/admin/library/'.$item->id.'/edit');
        $this->assertNull($item->fresh()->published_at);
        $this->assertEquals($before, $item->fresh()->except(['published_at', 'updated_at']));
        foreach ($relations as $name => $rows) {
            $this->assertEquals($rows->toArray(), $item->fresh()->load($name)->getRelation($name)->toArray());
        }
        Storage::disk('local')->assertExists([$file->path, 'cover.png']);
        $this->assertDatabaseHas('library_unlocks', ['user_id' => $user->id, 'item_id' => $item->id]);
        $this->assertDatabaseMissing('library_item_access', ['item_id' => $item->id]);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $item->id, 'action' => 'updated', 'admin_user_id' => $admin->id]);
        $this->getJson($url)->assertNotFound();
        $this->getJson($url.'/related')->assertNotFound();
        $this->postJson($url.'/unlock', ['credits' => 2])->assertNotFound();
        $this->postJson($url.'/attachments/'.$file->id.'/open')->assertNotFound();
        $this->get($signed)->assertNotFound();
        $this->getJson('/api/library/'.$related->id.'/related')->assertJsonMissing(['id' => $item->id]);
        $this->post('/admin/library/'.$item->id.'/unpublish')->assertRedirect();
        $this->assertSame(1, DB::table('audit_logs')->where('target_id', $item->id)->count());
        $this->put('/admin/library/'.$item->id, ['title' => 'Edited lesson', 'format' => 'article', 'published_at' => now()->subMinute()->toDateTimeString()])->assertSessionHasNoErrors();
        $this->getJson($url)->assertOk()->assertJsonPath('body', 'Saved body')->assertJsonPath('item.title', 'Edited lesson');
        $this->assertDatabaseHas('library_item_access', ['user_id' => $user->id, 'item_id' => $item->id, 'reason' => 'unlocked']);
    }

    public function test_unpublish_requires_an_authorized_editor(): void
    {
        $item = LibraryItem::create(['title' => 'Lesson', 'slug' => 'lesson', 'format' => 'article', 'published_at' => now()]);
        $url = '/admin/library/'.$item->id.'/unpublish';
        $this->post($url)->assertRedirect('/admin/login');
        $support = AdminUser::create(['name' => 'Support', 'email' => 'support@example.test', 'password' => 'password', 'role' => 'support', 'active' => true]);
        $this->actingAs($support, 'admin')->post($url)->assertForbidden();
        $this->assertNotNull($item->fresh()->published_at);
    }
}
