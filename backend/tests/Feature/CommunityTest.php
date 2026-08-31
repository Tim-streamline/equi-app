<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\AdminUser;
use App\Models\CommunityCategory;
use App\Models\CommunityPost;
use App\Models\CommunityReply;
use App\Models\CommunityTag;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CommunityTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $author;

    private CommunityPost $post;

    private CommunityCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = User::factory()->create();
        $this->author = User::factory()->create();
        $this->category = CommunityCategory::create(['slug' => 'vraag-shelley', 'label' => 'Vraag Shelley']);
        $this->post = CommunityPost::create(['author_user_id' => $this->author->id, 'author_name' => 'Author', 'body' => 'Question', 'category_id' => $this->category->id]);
        $this->asMember();
    }

    private function asMember(?User $user = null): void
    {
        $id = ($user ?? $this->member)->id;
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($id)
        {
            public function __construct(private string $id) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->id);

                return $next($request);
            }
        });
    }

    private function paid(string $slug = 'basic'): Subscription
    {
        $plan = Plan::create(['slug' => $slug, 'label' => $slug, 'name' => $slug, 'price_cents' => 900, 'interval' => 'monthly']);

        return Subscription::create(['user_id' => $this->member->id, 'plan_id' => $plan->id, 'status' => 'active', 'price_cents' => 900, 'interval' => 'monthly', 'started_at' => now()->subDay(), 'renews_at' => now()->addMonth()]);
    }

    public function test_free_reads_and_bookmarks_but_cannot_publish_reply_or_like(): void
    {
        $this->getJson('/api/community')->assertOk()->assertJsonPath('canParticipate', false)->assertJsonPath('posts.data.0.body', 'Question');
        $this->getJson('/api/community/posts/'.$this->post->id)->assertOk();
        $this->putJson('/api/community/posts/'.$this->post->id.'/bookmark')->assertOk();
        $this->getJson('/api/community?bookmarked=1')->assertOk()->assertJsonCount(1, 'posts.data');
        $this->postJson('/api/community/posts', ['body' => 'New'])->assertForbidden();
        $this->postJson('/api/community/posts/'.$this->post->id.'/replies', ['body' => 'Reply'])->assertForbidden();
        $this->putJson('/api/community/posts/'.$this->post->id.'/like')->assertForbidden();
    }

    public function test_basic_and_plus_can_publish_without_forging_author_or_expert_state(): void
    {
        foreach (['basic', 'plus'] as $slug) {
            $subscription = $this->paid($slug);
            $id = $this->postJson('/api/community/posts', ['body' => 'My question', 'category_id' => $this->category->id, 'author_user_id' => $this->author->id, 'has_expert_reply' => true, 'moderation_status' => 'pinned'])
                ->assertCreated()->json('id');
            $this->assertDatabaseHas('community_posts', ['id' => $id, 'author_user_id' => $this->member->id, 'has_expert_reply' => false, 'moderation_status' => 'visible']);
            $subscription->delete();
        }
    }

    public function test_reply_to_someone_else_is_saved_and_counter_is_server_managed(): void
    {
        $this->paid();
        $reply = $this->postJson('/api/community/posts/'.$this->post->id.'/replies', ['body' => 'First', 'author_is_expert' => true])->assertCreated()->json('id');
        $this->postJson('/api/community/posts/'.$this->post->id.'/replies', ['body' => 'Follow-up', 'parent_reply_id' => $reply])->assertCreated();
        $this->assertDatabaseHas('community_posts', ['id' => $this->post->id, 'replies_count' => 2, 'has_expert_reply' => false]);
        $this->getJson('/api/community/posts/'.$this->post->id)->assertOk()->assertJsonPath('replies.data.1.parentReply.body', 'First');
    }

    public function test_cross_thread_reply_reference_is_rejected(): void
    {
        $this->paid();
        $other = CommunityPost::create(['body' => 'Other']);
        $reply = CommunityReply::create(['post_id' => $other->id, 'body' => 'Other reply']);
        $this->postJson('/api/community/posts/'.$this->post->id.'/replies', ['body' => 'Invalid', 'parent_reply_id' => $reply->id])->assertUnprocessable();
    }

    public function test_likes_and_bookmarks_are_idempotent_and_private(): void
    {
        $this->paid();
        foreach ([1, 2] as $_) {
            $this->putJson('/api/community/posts/'.$this->post->id.'/like')->assertOk();
            $this->putJson('/api/community/posts/'.$this->post->id.'/bookmark')->assertOk();
        }
        $this->assertSame(1, $this->post->fresh()->likes_count);
        $this->asMember($this->author);
        $this->getJson('/api/community?bookmarked=1')->assertJsonCount(0, 'posts.data');
        $this->asMember();
        $this->deleteJson('/api/community/posts/'.$this->post->id.'/like')->assertOk();
        $this->deleteJson('/api/community/posts/'.$this->post->id.'/bookmark')->assertOk();
        $this->assertSame(0, $this->post->fresh()->likes_count);
    }

    public function test_hidden_content_disappears_from_feed_bookmarks_thread_and_reply_references(): void
    {
        $this->putJson('/api/community/posts/'.$this->post->id.'/bookmark')->assertOk();
        $this->post->update(['moderation_status' => 'hidden']);
        $this->getJson('/api/community')->assertJsonCount(0, 'posts.data');
        $this->getJson('/api/community?bookmarked=1')->assertJsonCount(0, 'posts.data');
        $this->getJson('/api/community/posts/'.$this->post->id)->assertNotFound();
    }

    public function test_locked_is_readable_but_cannot_receive_replies_and_pinned_sorts_first(): void
    {
        $this->paid();
        $this->post->update(['moderation_status' => 'locked']);
        $this->getJson('/api/community/posts/'.$this->post->id)->assertOk()->assertJsonPath('post.locked', true);
        $this->postJson('/api/community/posts/'.$this->post->id.'/replies', ['body' => 'No'])->assertForbidden();
        $pinned = CommunityPost::create(['body' => 'Pinned', 'moderation_status' => 'pinned']);
        $this->getJson('/api/community')->assertJsonPath('posts.data.0.id', $pinned->id);
    }

    public function test_only_owner_can_edit_delete_and_expired_members_cannot_publish(): void
    {
        $subscription = $this->paid();
        $this->patchJson('/api/community/posts/'.$this->post->id, ['body' => 'Hijacked'])->assertForbidden();
        $this->deleteJson('/api/community/posts/'.$this->post->id)->assertForbidden();
        $subscription->update(['renews_at' => now()->subDay()]);
        $this->postJson('/api/community/posts', ['body' => 'Expired'])->assertForbidden();
    }

    public function test_free_can_report_and_mute_then_unmute(): void
    {
        $this->postJson('/api/community/posts/'.$this->post->id.'/report', ['reason' => 'medical_risk', 'detail' => 'Please review'])->assertCreated();
        $this->assertDatabaseHas('moderation_reports', ['subject_id' => $this->post->id, 'reporter_user_id' => $this->member->id, 'status' => 'open']);
        $this->putJson('/api/community/mutes/user/'.$this->author->id)->assertOk();
        $this->getJson('/api/community')->assertJsonCount(0, 'posts.data');
        $this->getJson('/api/community/mutes')->assertJsonCount(1, 'data');
        $this->deleteJson('/api/community/mutes/user/'.$this->author->id)->assertOk();
        $this->getJson('/api/community')->assertJsonCount(1, 'posts.data');
    }

    public function test_posts_accept_private_media_and_hide_it_when_post_is_hidden(): void
    {
        Storage::fake('local');
        $this->paid();
        $id = $this->post('/api/community/posts', ['body' => 'Photo post', 'media' => [UploadedFile::fake()->image('horse.jpg')]], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $media = DB::table('community_media')->where('post_id', $id)->first();
        $this->assertNotNull($media);
        Storage::disk('local')->assertExists($media->path);
        $this->get('/api/community/media/'.$media->id)->assertOk();
        CommunityPost::findOrFail($id)->update(['moderation_status' => 'hidden']);
        $this->getJson('/api/community/media/'.$media->id)->assertNotFound();
    }

    public function test_generic_sync_cannot_bypass_community_access_or_moderation(): void
    {
        $this->asMember($this->author);
        $this->postJson('/api/sync/upload', ['operations' => [['op' => 'PATCH', 'type' => 'community_posts', 'id' => $this->post->id, 'data' => ['moderation_status' => 'pinned']]]])->assertForbidden();
    }

    public function test_multipart_edits_can_replace_attachments_and_clear_tags(): void
    {
        Storage::fake('local');
        $this->paid();
        $tag = CommunityTag::create(['label' => 'Training', 'slug' => 'training']);
        $id = $this->post('/api/community/posts', ['body' => 'Initial', 'tags' => json_encode([$tag->id]), 'media' => [UploadedFile::fake()->image('horse.jpg')]], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $old = DB::table('community_media')->where('post_id', $id)->first();
        $this->assertDatabaseHas('community_post_tags', ['post_id' => $id, 'tag_id' => $tag->id]);
        $this->post('/api/community/posts/'.$id, ['_method' => 'PATCH', 'body' => 'Edited', 'category_id' => $this->category->id, 'tags' => '[]', 'remove_media' => [$old->id], 'media' => [UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4')]], ['Accept' => 'application/json'])->assertOk();
        $this->getJson('/api/community/posts/'.$id)->assertOk()->assertJsonPath('post.body', 'Edited')->assertJsonCount(0, 'post.tags')->assertJsonCount(1, 'post.media')->assertJsonPath('post.media.0.type', 'video');
        Storage::disk('local')->assertMissing($old->path);
        $this->assertNotNull(CommunityPost::find($id)->edited_at);
    }

    public function test_upload_validation_rolls_back_invalid_posts_and_keeps_existing_attachments(): void
    {
        Storage::fake('local');
        $this->paid();
        $this->post('/api/community/posts', ['body' => 'Bad', 'media' => [UploadedFile::fake()->create('payload.svg', 1, 'image/svg+xml')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/community/posts', ['body' => 'Big', 'media' => [UploadedFile::fake()->create('clip.mp4', 20481, 'video/mp4')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $id = $this->post('/api/community/posts', ['body' => 'Original', 'media' => array_map(fn ($i) => UploadedFile::fake()->image("horse$i.jpg"), range(1, 4))], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->post('/api/community/posts/'.$id, ['_method' => 'PATCH', 'body' => 'Must roll back', 'media' => [UploadedFile::fake()->image('fifth.jpg')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseHas('community_posts', ['id' => $id, 'body' => 'Original']);
        $this->assertCount(4, Storage::disk('local')->allFiles());
    }

    public function test_hidden_and_muted_parent_references_do_not_leak_content_or_expert_preview(): void
    {
        $parent = CommunityReply::create(['post_id' => $this->post->id, 'body' => 'Private expert answer', 'author_user_id' => $this->author->id, 'author_is_expert' => true]);
        CommunityReply::create(['post_id' => $this->post->id, 'body' => 'Follow-up', 'parent_reply_id' => $parent->id]);
        $parent->update(['moderation_status' => 'hidden']);
        $this->getJson('/api/community/posts/'.$this->post->id)->assertJsonCount(1, 'replies.data')->assertJsonPath('replies.data.0.parentReply', null)->assertJsonPath('post.expertReply', null)->assertDontSee('Private expert answer');
        $parent->update(['moderation_status' => 'visible', 'author_user_id' => $this->member->id]);
        $this->asMember($this->author);
        $this->putJson('/api/community/mutes/user/'.$this->member->id)->assertOk();
        $this->getJson('/api/community/posts/'.$this->post->id)->assertJsonPath('replies.data.0.parentReply', null)->assertJsonPath('post.expertReply', null)->assertDontSee('Private expert answer');
    }

    public function test_delete_parent_keeps_flat_replies_and_cleans_likes_and_counters(): void
    {
        $subscription = $this->paid();
        $parent = $this->postJson('/api/community/posts/'.$this->post->id.'/replies', ['body' => 'Parent'])->assertCreated()->json('id');
        $child = $this->postJson('/api/community/posts/'.$this->post->id.'/replies', ['body' => 'Child', 'parent_reply_id' => $parent])->assertCreated()->json('id');
        $this->putJson('/api/community/replies/'.$parent.'/like')->assertOk();
        $this->patchJson('/api/community/replies/'.$child, ['body' => 'Edited child'])->assertOk();
        $subscription->delete();
        $this->deleteJson('/api/community/replies/'.$parent)->assertOk();
        $this->assertDatabaseHas('community_replies', ['id' => $child, 'body' => 'Edited child', 'parent_reply_id' => null]);
        $this->assertDatabaseMissing('community_reactions', ['target_id' => $parent]);
        $this->assertSame(1, $this->post->fresh()->replies_count);
    }

    public function test_owner_can_delete_after_downgrade_with_cascaded_bookmarks_and_replies(): void
    {
        $subscription = $this->paid();
        $id = $this->postJson('/api/community/posts', ['body' => 'Own'])->assertCreated()->json('id');
        $reply = $this->postJson('/api/community/posts/'.$id.'/replies', ['body' => 'Reply'])->assertCreated()->json('id');
        $this->putJson('/api/community/posts/'.$id.'/bookmark')->assertOk();
        $this->putJson('/api/community/posts/'.$id.'/like')->assertOk();
        $this->putJson('/api/community/replies/'.$reply.'/like')->assertOk();
        $subscription->delete();
        $this->deleteJson('/api/community/posts/'.$id)->assertOk();
        $this->assertDatabaseMissing('community_bookmarks', ['post_id' => $id]);
        $this->assertDatabaseMissing('community_replies', ['post_id' => $id]);
        $this->assertDatabaseMissing('community_reactions', ['target_id' => $id]);
        $this->assertDatabaseMissing('community_reactions', ['target_id' => $reply]);
    }

    public function test_account_restrictions_override_membership_until_lifted(): void
    {
        $this->paid();
        DB::table('user_restrictions')->insert(['user_id' => $this->member->id, 'type' => 'mute', 'expires_at' => now()->addDay()]);
        $this->getJson('/api/community')->assertOk()->assertJsonPath('canParticipate', false);
        $this->postJson('/api/community/posts', ['body' => 'Restricted'])->assertForbidden();
        DB::table('user_restrictions')->where('user_id', $this->member->id)->update(['lifted_at' => now()]);
        $this->getJson('/api/community')->assertJsonPath('canParticipate', true);
        $this->member->update(['disabled_at' => now()]);
        $this->getJson('/api/community')->assertForbidden();
    }

    public function test_therapist_mute_hides_expert_replies_and_preview(): void
    {
        $therapist = Therapist::create(['name' => 'Shelley']);
        CommunityReply::create(['post_id' => $this->post->id, 'body' => 'Expert answer', 'author_therapist_id' => $therapist->id, 'author_is_expert' => true]);
        $this->getJson('/api/community')->assertJsonPath('posts.data.0.expertReply.body', 'Expert answer');
        $this->putJson('/api/community/mutes/therapist/'.$therapist->id)->assertOk();
        $this->getJson('/api/community/posts/'.$this->post->id)->assertJsonCount(0, 'replies.data')->assertJsonPath('post.expertReply', null);
    }

    public function test_filters_categories_and_pagination_are_server_side(): void
    {
        CommunityCategory::create(['slug' => 'mijn-focus', 'label' => 'Mijn focus']);
        for ($i = 0; $i < 21; $i++) {
            CommunityPost::create(['body' => "General $i"]);
        }
        $this->getJson('/api/community')->assertJsonCount(20, 'posts.data')->assertJsonPath('posts.last_page', 2)->assertDontSee('Mijn focus');
        $this->getJson('/api/community?page=2')->assertJsonCount(2, 'posts.data');
        $this->getJson('/api/community?category_id='.$this->category->id)->assertJsonCount(1, 'posts.data');
        $this->getJson('/api/community?category_id=invalid')->assertUnprocessable();
    }

    public function test_anonymous_clients_cannot_read_content_or_media(): void
    {
        $this->app->forgetInstance(AuthenticatePowerSyncJwt::class);
        $this->app->bind(AuthenticatePowerSyncJwt::class, AuthenticatePowerSyncJwt::class);
        $this->getJson('/api/community')->assertUnauthorized();
        $this->getJson('/api/community/posts/'.$this->post->id)->assertUnauthorized();
        $this->getJson('/api/community/media/'.$this->post->id)->assertUnauthorized();
    }

    public function test_admin_moderation_updates_expert_state_and_private_media_requires_community_role(): void
    {
        Storage::fake('local');
        $this->paid();
        $id = $this->post('/api/community/posts', ['body' => 'Review photo', 'media' => [UploadedFile::fake()->image('horse.jpg')]], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $media = DB::table('community_media')->where('post_id', $id)->first();
        $admin = AdminUser::create(['name' => 'Moderator', 'email' => 'mod@example.test', 'password' => 'password', 'role' => 'moderator', 'active' => true]);
        $reply = CommunityReply::create(['post_id' => $id, 'body' => 'Expert reply', 'author_is_expert' => true]);
        $this->actingAs($admin, 'admin')->post('/admin/community/reply/'.$reply->id.'/moderate', ['status' => 'hidden'])->assertRedirect();
        $this->assertDatabaseHas('community_posts', ['id' => $id, 'has_expert_reply' => false, 'replies_count' => 0]);
        $this->post('/admin/community/post/'.$id.'/moderate', ['status' => 'hidden'])->assertRedirect();
        $this->get('/admin/community/media/'.$media->id)->assertOk();
        $this->getJson('/api/community/media/'.$media->id)->assertNotFound();
        $admin->update(['role' => 'support']);
        $this->get('/admin/community/media/'.$media->id)->assertForbidden();
    }
}
