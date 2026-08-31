<?php

namespace App\Support;

use App\Models\CommunityPost;
use App\Models\CommunityReply;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunityAccess
{
    public function user(Request $request): User
    {
        $user = User::find($request->attributes->get('powersync_user_id'));
        abort_unless($user, 401);
        abort_if($user->disabled_at, 403, 'Je account is uitgeschakeld.');

        return $user;
    }

    public function canParticipate(User $user): bool
    {
        $restricted = DB::table('user_restrictions')->where('user_id', $user->id)
            ->whereIn('type', ['mute', 'ban'])->whereNull('lifted_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();

        return ! $restricted && Subscription::where('user_id', $user->id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('started_at')->orWhereDate('started_at', '<=', today()))
            ->where(fn ($q) => $q->whereNull('renews_at')->orWhereDate('renews_at', '>=', today()))
            ->whereHas('plan', fn ($q) => $q->whereIn('slug', ['basic', 'basis', 'plus', 'bundle']))->exists();
    }

    public function participate(User $user): void
    {
        abort_unless($this->canParticipate($user), 403, 'Plaatsen en reageren kan met Basic of Plus, zolang je account niet beperkt is.');
    }

    public function visible(Builder $query, User $user, bool $replies = false): Builder
    {
        $query->where('moderation_status', '!=', 'hidden')
            ->where(fn ($q) => $q->whereNull('author_user_id')->orWhereNotIn('author_user_id',
                DB::table('community_mutes')->select('target_id')->where('user_id', $user->id)->where('target_type', 'user')));
        if ($replies) {
            $query->where(fn ($q) => $q->whereNull('author_therapist_id')->orWhereNotIn('author_therapist_id',
                DB::table('community_mutes')->select('target_id')->where('user_id', $user->id)->where('target_type', 'therapist')));
        }

        return $query;
    }

    public function post(User $user, string $id, bool $lock = false): CommunityPost
    {
        $query = $this->visible(CommunityPost::query(), $user)->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public function reply(User $user, string $id): CommunityReply
    {
        $reply = $this->visible(CommunityReply::query(), $user, true)->whereKey($id)->firstOrFail();
        $this->post($user, $reply->post_id);

        return $reply;
    }

    public static function recount(CommunityPost $post): void
    {
        $post->update([
            'replies_count' => $post->replies()->where('moderation_status', '!=', 'hidden')->count(),
            'likes_count' => $post->reactions()->where('kind', 'like')->count(),
            'has_expert_reply' => $post->replies()->where('moderation_status', '!=', 'hidden')->where('author_is_expert', true)->exists(),
        ]);
    }
}
