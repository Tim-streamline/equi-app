<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommunityPost;
use App\Models\CommunityReply;
use App\Models\ModerationReport;
use App\Models\Therapist;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class CommunityController extends Controller
{
    public function index(Request $request): Response
    {
        $posts = CommunityPost::query()
            ->when($request->string('q')->toString(), fn ($query, $q) => $query->where('body', 'ilike', "%{$q}%"))
            ->when($request->string('status')->toString(), fn ($query, $s) => $query->where('moderation_status', $s))
            ->when($request->boolean('expert_queue'), fn ($query) => $query
                ->where('has_expert_reply', false)
                ->whereHas('category', fn ($c) => $c->where('slug', 'vraag-shelley')))
            ->with('author:id,name', 'category:id,label')
            ->withCount('reports')
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Community/Index', [
            'posts' => $posts,
            'filters' => $request->only('q', 'status', 'expert_queue'),
            'openReports' => ModerationReport::where('status', 'open')->count(),
        ]);
    }

    public function show(CommunityPost $post): Response
    {
        $post->load([
            'author:id,name,email',
            'category:id,label',
            'tags:id,label',
            'replies.authorUser:id,name',
            'replies.authorTherapist:id,name',
        ]);

        return Inertia::render('Community/Show', [
            'post' => $post,
            'reports' => ModerationReport::where('subject_id', $post->id)->orWhereIn(
                'subject_id', $post->replies->pluck('id')
            )->with('reporter:id,name')->latest()->get(),
            'therapists' => Therapist::orderBy('name')->get(['id', 'name', 'avatar_initial', 'avatar_color']),
        ]);
    }

    public function moderatePost(Request $request, CommunityPost $post): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', 'in:visible,hidden,locked,pinned']])['status'];
        $before = $post->only('moderation_status');
        $post->update(['moderation_status' => $status, 'reviewed_at' => Carbon::now()]);
        AuditLogger::updated($post, $before, $request->input('reason'));

        return back()->with('success', "Post marked {$status}.");
    }

    public function destroyPost(CommunityPost $post): RedirectResponse
    {
        AuditLogger::deleted($post);
        $post->delete();

        return redirect()->route('admin.community.index')->with('success', 'Post deleted.');
    }

    public function moderateReply(Request $request, CommunityReply $reply): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', 'in:visible,hidden']])['status'];
        $before = $reply->only('moderation_status');
        $reply->update(['moderation_status' => $status, 'reviewed_at' => Carbon::now()]);
        AuditLogger::updated($reply, $before);

        return back()->with('success', "Reply marked {$status}.");
    }

    public function destroyReply(CommunityReply $reply): RedirectResponse
    {
        AuditLogger::deleted($reply);
        $reply->delete();
        $this->recountPost($reply->post_id);

        return back()->with('success', 'Reply deleted.');
    }

    public function expertReply(Request $request, CommunityPost $post): RedirectResponse
    {
        $data = $request->validate([
            'therapist_id' => ['required', 'exists:therapists,id'],
            'body' => ['required', 'string'],
        ]);
        $therapist = Therapist::find($data['therapist_id']);

        $reply = $post->replies()->create([
            'author_therapist_id' => $therapist->id,
            'author_name' => $therapist->name,
            'author_initial' => $therapist->avatar_initial,
            'author_avatar_color' => $therapist->avatar_color,
            'author_is_expert' => true,
            'body' => $data['body'],
            'order' => ($post->replies()->max('order') ?? 0) + 1,
        ]);
        $post->update(['has_expert_reply' => true]);
        $this->recountPost($post->id);
        AuditLogger::log('expert_reply', $post, after: ['reply_id' => $reply->id, 'therapist' => $therapist->name]);

        return back()->with('success', 'Expert reply posted.');
    }

    public function recount(CommunityPost $post): RedirectResponse
    {
        $this->recountPost($post->id);
        AuditLogger::log('recount', $post);

        return back()->with('success', 'Counters recalculated.');
    }

    private function recountPost(string $postId): void
    {
        $post = CommunityPost::find($postId);
        if (! $post) {
            return;
        }
        $post->update([
            'replies_count' => $post->replies()->count(),
            'likes_count' => $post->reactions()->count(),
            'has_expert_reply' => $post->replies()->where('author_is_expert', true)->exists(),
        ]);
    }
}
