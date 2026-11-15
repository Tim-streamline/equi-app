<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\CommunityCategory;
use App\Models\CommunityPost;
use App\Models\CommunityReaction;
use App\Models\CommunityReply;
use App\Models\CommunityTag;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Feed and thread presentation: visibility-aware content with a bounded number of queries. */
class CommunityPresentationTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->viewer = User::factory()->create(['name' => 'Viewer']);
        $this->other = User::factory()->create(['name' => 'Other']);
        $id = $this->viewer->id;
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

    private function at(int $minutesAgo): Carbon
    {
        return now()->subMinutes($minutesAgo);
    }

    private function makePost(array $attributes, int $minutesAgo): CommunityPost
    {
        $post = CommunityPost::create($attributes + ['author_name' => 'Author', 'body' => 'Post']);
        $post->forceFill(['created_at' => $this->at($minutesAgo), 'updated_at' => $this->at($minutesAgo)])->save();

        return $post;
    }

    private function makeReply(CommunityPost $post, array $attributes, int $minutesAgo): CommunityReply
    {
        $reply = CommunityReply::create($attributes + ['post_id' => $post->id, 'author_name' => 'Replier', 'body' => 'Reply']);
        $reply->forceFill(['created_at' => $this->at($minutesAgo), 'updated_at' => $this->at($minutesAgo)])->save();

        return $reply;
    }

    private function react(User $user, string $type, string $id, string $kind = 'like'): void
    {
        CommunityReaction::create(['user_id' => $user->id, 'target_type' => $type, 'target_id' => $id, 'kind' => $kind]);
    }

    private function mute(string $type, string $id): void
    {
        DB::table('community_mutes')->insert(['user_id' => $this->viewer->id, 'target_type' => $type, 'target_id' => $id]);
    }

    private function media(CommunityPost $post, string $mime, int $order): string
    {
        $id = (string) Str::uuid();
        DB::table('community_media')->insert(['id' => $id, 'post_id' => $post->id, 'path' => 'community/'.$id, 'mime_type' => $mime,
            'size' => 10, 'order' => $order, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $muted = User::factory()->create(['name' => 'Muted']);
        $mutedTherapist = Therapist::create(['name' => 'Muted therapist']);
        $therapist = Therapist::create(['name' => 'Therapist']);
        $this->mute('user', $muted->id);
        $this->mute('therapist', $mutedTherapist->id);
        $category = CommunityCategory::create(['slug' => 'voeding', 'label' => 'Voeding']);
        $tagA = CommunityTag::create(['slug' => 'hoef', 'label' => 'Hoef']);
        $tagB = CommunityTag::create(['slug' => 'gras', 'label' => 'Gras']);

        // Stored counters are deliberately stale: presentation counts reactions.
        $main = $this->makePost(['author_user_id' => $this->other->id, 'author_name' => 'Other', 'author_initial' => 'O', 'body' => 'Main',
            'category_id' => $category->id, 'likes_count' => 99, 'replies_count' => 42, 'has_expert_reply' => false], 300);
        $main->tags()->sync([$tagA->id, $tagB->id]);
        $video = $this->media($main, 'video/mp4', 1);
        $image = $this->media($main, 'image/jpeg', 0);
        DB::table('community_bookmarks')->insert(['user_id' => $this->viewer->id, 'post_id' => $main->id]);
        $this->react($this->viewer, 'post', $main->id);
        $this->react($this->other, 'post', $main->id);
        $this->react($this->other, 'post', $main->id, 'insight');

        $pinned = $this->makePost(['author_user_id' => $this->other->id, 'body' => 'Pinned', 'moderation_status' => 'pinned'], 400);
        $elsewhere = $this->makeReply($pinned, ['body' => 'Elsewhere', 'author_is_expert' => true, 'author_therapist_id' => $therapist->id], 390);
        $this->makePost(['author_user_id' => $muted->id, 'body' => 'Muted post'], 100);
        $this->makePost(['author_user_id' => $this->other->id, 'body' => 'Hidden post', 'moderation_status' => 'hidden'], 90);
        $own = $this->makePost(['author_user_id' => $this->viewer->id, 'body' => 'Own', 'moderation_status' => 'locked'], 80);
        $own->forceFill(['edited_at' => $this->at(70)])->save();

        $oldExpert = $this->makeReply($main, ['body' => 'Old expert', 'author_is_expert' => true, 'author_therapist_id' => $therapist->id], 240);
        $hiddenExpert = $this->makeReply($main, ['body' => 'Hidden expert', 'author_is_expert' => true, 'moderation_status' => 'hidden'], 180);
        $this->makeReply($main, ['body' => 'Muted therapist expert', 'author_is_expert' => true, 'author_therapist_id' => $mutedTherapist->id], 120);
        $mutedUserExpert = $this->makeReply($main, ['body' => 'Muted user expert', 'author_is_expert' => true, 'author_user_id' => $muted->id], 110);
        $liked = $this->makeReply($main, ['body' => 'Liked', 'author_user_id' => $this->other->id, 'parent_reply_id' => $oldExpert->id], 60);
        $this->makeReply($main, ['body' => 'Under hidden', 'parent_reply_id' => $hiddenExpert->id], 50);
        $this->makeReply($main, ['body' => 'Cross post parent', 'parent_reply_id' => $elsewhere->id], 40);
        $this->makeReply($main, ['body' => 'By muted user', 'author_user_id' => $muted->id], 30);
        $this->makeReply($main, ['body' => 'Under muted', 'author_user_id' => $this->viewer->id, 'parent_reply_id' => $mutedUserExpert->id], 20);
        $latestExpert = $this->makeReply($main, ['body' => str_repeat('Latest expert ', 20), 'author_is_expert' => true,
            'author_therapist_id' => $therapist->id, 'parent_reply_id' => $liked->id, 'likes_count' => 7], 10);
        $this->makeReply($main, ['body' => 'Newest hidden expert', 'author_is_expert' => true, 'moderation_status' => 'hidden'], 5);
        $this->makeReply($main, ['body' => 'Newest muted expert', 'author_is_expert' => true, 'author_therapist_id' => $mutedTherapist->id], 1);
        $this->react($this->viewer, 'reply', $liked->id);
        $this->react($this->other, 'reply', $latestExpert->id);
        $this->react($this->other, 'reply', $liked->id, 'insight');

        return compact('main', 'pinned', 'own', 'liked', 'latestExpert', 'video', 'image', 'tagA', 'tagB', 'category');
    }

    public function test_feed_and_thread_present_visible_content_with_live_reaction_counts(): void
    {
        $f = $this->fixture();

        $feed = $this->getJson('/api/community')->assertOk();
        $this->assertSame(['Pinned', 'Own', 'Main'], array_column($feed->json('posts.data'), 'body'));
        $main = $feed->json('posts.data.2');
        $this->assertSame(['id' => $f['category']->id, 'label' => 'Voeding', 'slug' => 'voeding'], $main['category']);
        $this->assertEqualsCanonicalizing(['Hoef', 'Gras'], array_column($main['tags'], 'label'));
        $this->assertTrue($main['bookmarked']);
        $this->assertSame(2, $main['likesCount']);
        $this->assertTrue($main['liked']);
        $this->assertSame(6, $main['repliesCount']);
        $this->assertSame([$f['image'], $f['video']], array_column($main['media'], 'id'));
        $this->assertSame(['image', 'video'], array_column($main['media'], 'type'));
        $this->assertSame($f['latestExpert']->id, $main['expertReply']['id']);
        $this->assertSame(1, $main['expertReply']['likesCount']);
        $this->assertFalse($main['expertReply']['liked']);
        $this->assertSame($f['liked']->id, $main['expertReply']['parentReply']['id']);
        $pinned = $feed->json('posts.data.0');
        $this->assertTrue($pinned['pinned']);
        $this->assertSame('Elsewhere', $pinned['expertReply']['body']);
        $this->assertSame([], $pinned['tags']);
        $this->assertNull($pinned['category']);
        $this->assertFalse($pinned['bookmarked']);
        $own = $feed->json('posts.data.1');
        $this->assertTrue($own['owned'] && $own['locked']);
        $this->assertSame(0, $own['repliesCount']);
        $this->assertNull($own['expertReply']);

        $thread = $this->getJson('/api/community/posts/'.$f['main']->id)->assertOk();
        $this->assertSame($main, $thread->json('post'));
        $replies = collect($thread->json('replies.data'))->keyBy('body');
        $this->assertSame(['Old expert', 'Liked', 'Under hidden', 'Cross post parent', 'Under muted', str_repeat('Latest expert ', 20)], $replies->keys()->all());
        $this->assertSame(['id' => $replies['Old expert']['id'], 'authorName' => 'Replier', 'body' => 'Old expert'], $replies['Liked']['parentReply']);
        $this->assertSame([1, true], [$replies['Liked']['likesCount'], $replies['Liked']['liked']]);
        $this->assertNull($replies['Under hidden']['parentReply']);
        $this->assertNull($replies['Cross post parent']['parentReply']);
        $this->assertNull($replies['Under muted']['parentReply']);
        $this->assertNotNull($replies['Under muted']['parentReplyId']);
        $this->assertTrue($replies['Under muted']['owned']);
    }

    private function populate(int $posts, int $replies): CommunityPost
    {
        $category = CommunityCategory::firstOrCreate(['slug' => 'voeding'], ['label' => 'Voeding']);
        $tag = CommunityTag::firstOrCreate(['slug' => 'hoef'], ['label' => 'Hoef']);
        $first = null;
        for ($p = 0; $p < $posts; $p++) {
            $post = $this->makePost(['author_user_id' => $this->other->id, 'category_id' => $category->id], 1000 + $p);
            $post->tags()->sync([$tag->id]);
            $this->media($post, 'image/jpeg', 0);
            $this->react($this->viewer, 'post', $post->id);
            DB::table('community_bookmarks')->insert(['user_id' => $this->viewer->id, 'post_id' => $post->id]);
            $parent = null;
            for ($r = 0; $r < $replies; $r++) {
                $parent = $this->makeReply($post, ['author_is_expert' => $r % 2 === 0, 'parent_reply_id' => $parent?->id], 900 - $r);
                $this->react($this->other, 'reply', $parent->id);
            }
            $first ??= $post;
        }

        return $first;
    }

    private function queries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    public function test_feed_query_count_does_not_grow_with_posts(): void
    {
        $this->populate(2, 3);
        $small = $this->queries(fn () => $this->getJson('/api/community')->assertOk()->assertJsonCount(2, 'posts.data'));
        $this->populate(8, 3);
        $large = $this->queries(fn () => $this->getJson('/api/community')->assertOk()->assertJsonCount(10, 'posts.data'));

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(20, $large);
    }

    public function test_thread_query_count_does_not_grow_with_replies(): void
    {
        $small = $this->populate(1, 5);
        $few = $this->queries(fn () => $this->getJson('/api/community/posts/'.$small->id)->assertOk()->assertJsonCount(5, 'replies.data'));
        $large = $this->populate(1, 30);
        $many = $this->queries(fn () => $this->getJson('/api/community/posts/'.$large->id)->assertOk()->assertJsonCount(30, 'replies.data'));

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(20, $many);
    }
}
