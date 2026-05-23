<?php

namespace Database\Seeders;

use App\Models\CommunityCategory;
use App\Models\CommunityPost;
use App\Models\CommunityReaction;
use App\Models\CommunityReply;
use App\Models\CommunityTag;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Database\Seeder;

class CommunitySeeder extends Seeder
{
    private const AVATAR_COLORS = ['#5FD7CB', '#0D5C5B', '#127A79', '#2EA875', '#D9A441', '#C2543E'];

    public function run(): void
    {
        $shelley = Therapist::where('name', 'Shelley')->first();
        $therapistIds = Therapist::pluck('id')->all();
        $users = User::all();
        $categories = CommunityCategory::all();
        $tags = CommunityTag::all();

        // Hand-craft a couple of anchor posts that match the frontend mock.
        $anchor = $this->createPost(
            $users->where('email', '!=', 'marit@voorbeeld.nl')->random(),
            $categories->firstWhere('slug', 'vraag-shelley') ?? $categories->first(),
            'Mijn ruin krabt zijn manen al weken open. Voeding al aangepast, geen verbetering. Iemand ervaring met brandnetel-protocol?',
            ['jeukklachten', 'voeding'],
            $tags, true,
        );
        $this->createReply($anchor, null, $shelley, 'Hi! Als voeding al klopt is brandnetel zeker te proberen. Belangrijk: vers, niet gedroogd. Bouw op in 5 dagen. Stuur me eens een foto van zijn manen via de app, dan kijk ik mee.', 24, 4);
        $this->createReply($anchor, $users->random(), null, "Bij mijn merrie ook geholpen. Goed om Shelley's stappenplan te volgen — niet zelf experimenteren.", 5, 0);

        // Now fill up to ~80 posts.
        for ($i = 0; $i < 80; $i++) {
            $author = $users->random();
            $category = $categories->random();
            $body = fake()->paragraph(fake()->numberBetween(1, 3));
            $tagSlugs = fake()->randomElements($tags->pluck('slug')->all(), fake()->numberBetween(0, 3));
            $hasExpertReply = fake()->boolean(30);

            $post = $this->createPost($author, $category, $body, $tagSlugs, $tags, $hasExpertReply);

            // Random replies.
            $replyCount = fake()->numberBetween(0, 6);
            for ($r = 0; $r < $replyCount; $r++) {
                $isExpert = $hasExpertReply && $r === 0;
                if ($isExpert) {
                    $this->createReply($post, null, $shelley,
                        fake()->paragraph(fake()->numberBetween(1, 2)),
                        fake()->numberBetween(5, 40), fake()->numberBetween(0, 5));
                } else {
                    $this->createReply($post, $users->random(), null,
                        fake()->sentence(fake()->numberBetween(8, 20)),
                        fake()->numberBetween(0, 15), 0);
                }
            }

            // Random reactions on the post.
            $reactors = $users->random(fake()->numberBetween(0, min(10, $users->count())));
            foreach ($reactors as $u) {
                CommunityReaction::create([
                    'user_id' => $u->id,
                    'target_type' => 'post',
                    'target_id' => $post->id,
                    'kind' => 'like',
                ]);
            }
            $post->update(['likes_count' => $reactors->count()]);
        }
    }

    private function createPost(User $author, CommunityCategory $category, string $body, array $tagSlugs, $allTags, bool $hasExpertReply): CommunityPost
    {
        $when = fake()->dateTimeBetween('-2 months', 'now');
        $whenLabel = $this->relative($when);
        $post = CommunityPost::create([
            'author_user_id' => $author->id,
            'author_name' => $author->name,
            'author_initial' => mb_substr($author->name, 0, 1),
            'author_avatar_color' => fake()->randomElement(self::AVATAR_COLORS),
            'body' => $body,
            'when_label' => $whenLabel,
            'likes_count' => 0,
            'replies_count' => 0,
            'has_expert_reply' => $hasExpertReply,
            'category_id' => $category->id,
            'order' => fake()->numberBetween(0, 1000),
            'created_at' => $when, 'updated_at' => $when,
        ]);
        foreach ($tagSlugs as $i => $slug) {
            $tag = $allTags->firstWhere('slug', $slug);
            if (!$tag) continue;
            \App\Models\CommunityPostTag::create([
                'post_id' => $post->id, 'tag_id' => $tag->id, 'order' => $i,
            ]);
        }
        return $post;
    }

    private function createReply(CommunityPost $post, ?User $user, ?Therapist $therapist, string $body, int $likes, int $sub): void
    {
        $when = fake()->dateTimeBetween($post->created_at, 'now');
        CommunityReply::create([
            'post_id' => $post->id,
            'author_user_id' => $user?->id,
            'author_therapist_id' => $therapist?->id,
            'author_name' => $user?->name ?? $therapist?->name,
            'author_initial' => mb_substr($user?->name ?? $therapist?->name, 0, 1),
            'author_avatar_color' => $therapist ? '#0D5C5B' : fake()->randomElement(self::AVATAR_COLORS),
            'author_is_expert' => (bool) $therapist,
            'body' => $body,
            'when_label' => $this->relative($when),
            'likes_count' => $likes,
            'replies_count' => $sub,
            'order' => $post->replies()->count(),
            'created_at' => $when, 'updated_at' => $when,
        ]);
        $post->increment('replies_count');
    }

    private function relative(\DateTimeInterface $dt): string
    {
        $diff = now()->diff($dt);
        if ($diff->days === 0) {
            if ($diff->h === 0) return max(1, $diff->i) . ' min';
            return $diff->h . ' u';
        }
        if ($diff->days === 1) return 'gisteren';
        if ($diff->days < 7) return $diff->days . ' dagen geleden';
        if ($diff->days < 30) return ((int) ($diff->days / 7)) . ' wk geleden';
        return $dt->format('j M');
    }
}
