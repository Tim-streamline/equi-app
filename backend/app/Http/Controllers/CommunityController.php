<?php

namespace App\Http\Controllers;

use App\Models\CommunityCategory;
use App\Models\CommunityPost;
use App\Models\CommunityReply;
use App\Models\CommunityTag;
use App\Models\ModerationReport;
use App\Models\Therapist;
use App\Models\User;
use App\Support\CommunityAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Community uses authenticated HTTP, not client-writable sync rows. */
class CommunityController extends Controller
{
    public function __construct(private CommunityAccess $access) {}

    public function index(Request $request)
    {
        $user = $this->access->user($request);
        $request->validate(['category_id' => ['nullable', 'uuid'], 'page' => ['nullable', 'integer', 'min:1'], 'bookmarked' => ['nullable', 'boolean']]);
        $query = $this->access->visible(CommunityPost::query(), $user)
            ->when($request->input('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->boolean('bookmarked'), fn ($q) => $q->whereIn('id', DB::table('community_bookmarks')->select('post_id')->where('user_id', $user->id)))
            ->orderByRaw("CASE WHEN moderation_status = 'pinned' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')->orderByDesc('id');

        return response()->json([
            'posts' => $query->paginate(20)->through(fn ($post) => $this->presentPost($post, $user)),
            'categories' => CommunityCategory::whereNotIn('slug', ['alles', 'mijn-focus'])->orderBy('order')->get(['id', 'slug', 'label']),
            'tags' => CommunityTag::orderBy('label')->get(['id', 'label']),
            'canParticipate' => $this->access->canParticipate($user), 'currentUserId' => $user->id,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $post)
    {
        $user = $this->access->user($request);
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $model = $this->access->post($user, $post);

        return response()->json([
            'post' => $this->presentPost($model, $user),
            'replies' => $this->access->visible(CommunityReply::where('post_id', $post), $user, true)
                ->orderBy('created_at')->orderBy('id')->paginate(50)->through(fn ($r) => $this->presentReply($r, $user)),
            'canParticipate' => $this->access->canParticipate($user), 'currentUserId' => $user->id,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        return $this->savePost($request);
    }

    public function update(Request $request, string $post)
    {
        return $this->savePost($request, $post);
    }

    private function savePost(Request $request, ?string $id = null)
    {
        $user = $this->access->user($request);
        $this->access->participate($user);
        // Multipart uploads encode the optional tag array as JSON, including [].
        if (is_string($request->input('tags')) && is_array($tags = json_decode($request->input('tags'), true))) {
            $request->merge(['tags' => $tags]);
        }
        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'category_id' => ['nullable', 'uuid', Rule::exists('community_categories', 'id')->whereNotIn('slug', ['alles', 'mijn-focus'])],
            'tags' => ['sometimes', 'array', 'max:5'], 'tags.*' => ['uuid', 'distinct', 'exists:community_tags,id'],
            'media' => ['sometimes', 'array', 'max:4'],
            'media.*' => ['file', 'max:20480', 'mimes:jpg,jpeg,png,webp,mp4,mov,webm'],
            'remove_media' => ['sometimes', 'array', 'max:4'], 'remove_media.*' => ['uuid'],
        ]);
        $paths = [];
        $removed = [];
        try {
            $post = DB::transaction(function () use ($data, $id, $user, $request, &$paths, &$removed) {
                $post = $id ? $this->access->post($user, $id, true) : new CommunityPost;
                if ($id) {
                    abort_unless($post->author_user_id === $user->id, 403);
                    abort_if($post->moderation_status === 'locked', 403, 'Deze discussie is gesloten.');
                }
                $post->fill(['body' => trim($data['body']), 'category_id' => $data['category_id'] ?? null]);
                if (! $id) {
                    $post->fill(['author_user_id' => $user->id, 'author_name' => $user->name,
                        'author_initial' => $user->avatar_initial ?: mb_substr($user->name, 0, 1), 'author_avatar_color' => '#127A79']);
                } else {
                    $post->edited_at = now();
                }
                $post->save();
                $remove = DB::table('community_media')->where('post_id', $post->id)->whereIn('id', $data['remove_media'] ?? []);
                $removed = $remove->pluck('path')->all();
                $remove->delete();
                $existing = DB::table('community_media')->where('post_id', $post->id)->count();
                abort_if($existing + count($request->file('media', [])) > 4, 422, 'Maximaal vier foto’s of video’s per bericht.');
                foreach ($request->file('media', []) as $i => $file) {
                    $path = $file->store('community/'.$post->id, 'local');
                    abort_unless($path, 500, 'Upload kon niet worden opgeslagen.');
                    $paths[] = $path;
                    DB::table('community_media')->insert(['id' => (string) Str::uuid(), 'post_id' => $post->id,
                        'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'order' => $existing + $i,
                        'created_at' => now(), 'updated_at' => now()]);
                }
                if (array_key_exists('tags', $data)) {
                    $post->tags()->sync($data['tags']);
                }

                return $post;
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($paths);
            throw $error;
        }
        Storage::disk('local')->delete($removed);

        return response()->json(['id' => $post->id], $id ? 200 : 201);
    }

    public function destroy(Request $request, string $post)
    {
        $user = $this->access->user($request);
        // Owners can remove their own content even after downgrading.
        DB::transaction(function () use ($user, $post) {
            $model = CommunityPost::whereKey($post)->lockForUpdate()->firstOrFail();
            abort_unless($model->author_user_id === $user->id, 403);
            $model->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function replyStore(Request $request, string $post)
    {
        $user = $this->access->user($request);
        $this->access->participate($user);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000'], 'parent_reply_id' => ['nullable', 'uuid']]);
        $reply = DB::transaction(function () use ($post, $user, $data) {
            $model = $this->access->post($user, $post, true);
            abort_if($model->moderation_status === 'locked', 403, 'Deze discussie is gesloten.');
            if (! empty($data['parent_reply_id'])) {
                abort_unless($this->access->visible(CommunityReply::where('post_id', $post), $user, true)->whereKey($data['parent_reply_id'])->exists(), 422, 'Deze reactie is niet beschikbaar in deze discussie.');
            }
            $reply = $model->replies()->create([
                'body' => trim($data['body']), 'parent_reply_id' => $data['parent_reply_id'] ?? null,
                'author_user_id' => $user->id, 'author_name' => $user->name,
                'author_initial' => $user->avatar_initial ?: mb_substr($user->name, 0, 1),
                'author_is_expert' => false, 'order' => ($model->replies()->max('order') ?? 0) + 1,
            ]);
            CommunityAccess::recount($model);

            return $reply;
        });

        return response()->json(['id' => $reply->id], 201);
    }

    public function replyUpdate(Request $request, string $reply)
    {
        $user = $this->access->user($request);
        $this->access->participate($user);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        DB::transaction(function () use ($user, $reply, $data) {
            $model = $this->access->reply($user, $reply);
            $post = $this->access->post($user, $model->post_id, true);
            abort_unless($model->author_user_id === $user->id, 403);
            abort_if($post->moderation_status === 'locked', 403, 'Deze discussie is gesloten.');
            $model->update(['body' => trim($data['body']), 'edited_at' => now()]);
        });

        return response()->json(['ok' => true]);
    }

    public function replyDestroy(Request $request, string $reply)
    {
        $user = $this->access->user($request);
        DB::transaction(function () use ($user, $reply) {
            $model = CommunityReply::findOrFail($reply);
            abort_unless($model->author_user_id === $user->id, 403);
            $post = CommunityPost::whereKey($model->post_id)->lockForUpdate()->firstOrFail();
            $model->delete();
            CommunityAccess::recount($post);
        });

        return response()->json(['ok' => true]);
    }

    public function bookmark(Request $request, string $post)
    {
        $user = $this->access->user($request);
        $this->access->post($user, $post);
        $key = ['user_id' => $user->id, 'post_id' => $post];
        if ($request->isMethod('delete')) {
            DB::table('community_bookmarks')->where($key)->delete();
        } else {
            DB::table('community_bookmarks')->insertOrIgnore($key + ['created_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    public function like(Request $request, string $type, string $id)
    {
        $user = $this->access->user($request);
        if (! $request->isMethod('delete')) {
            $this->access->participate($user);
        }
        abort_unless(in_array($type, ['posts', 'replies']), 404);
        DB::transaction(function () use ($request, $user, $type, $id) {
            $target = $type === 'posts' ? $this->access->post($user, $id, true) : $this->access->reply($user, $id);
            if ($type === 'replies') {
                $this->access->post($user, $target->post_id, true);
            }
            $key = ['user_id' => $user->id, 'target_type' => $type === 'posts' ? 'post' : 'reply', 'target_id' => $id, 'kind' => 'like'];
            if ($request->isMethod('delete')) {
                DB::table('community_reactions')->where($key)->delete();
            } else {
                DB::table('community_reactions')->insertOrIgnore($key + ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
            }
            $target->update(['likes_count' => $target->reactions()->where('kind', 'like')->count()]);
        });

        return response()->json(['ok' => true]);
    }

    public function report(Request $request, string $type, string $id)
    {
        $user = $this->access->user($request);
        abort_unless(in_array($type, ['posts', 'replies']), 404);
        $target = $type === 'posts' ? $this->access->post($user, $id) : $this->access->reply($user, $id);
        abort_if($target->author_user_id === $user->id, 422, 'Je kunt je eigen bericht niet rapporteren.');
        $data = $request->validate(['reason' => ['required', 'in:spam,abuse,medical_risk,duplicate,other'], 'detail' => ['nullable', 'string', 'max:2000']]);
        ModerationReport::firstOrCreate(['subject_type' => $type === 'posts' ? 'post' : 'reply', 'subject_id' => $id, 'reporter_user_id' => $user->id, 'status' => 'open'], $data);

        return response()->json(['ok' => true], 201);
    }

    public function mutes(Request $request)
    {
        $user = $this->access->user($request);

        return response()->json(['data' => DB::table('community_mutes')->where('user_id', $user->id)->get()->map(function ($mute) {
            $author = $mute->target_type === 'user' ? User::find($mute->target_id) : Therapist::find($mute->target_id);

            return ['type' => $mute->target_type, 'id' => $mute->target_id, 'name' => $author?->name ?? 'Verwijderde gebruiker'];
        })])->header('Cache-Control', 'private, no-store');
    }

    public function mute(Request $request, string $type, string $id)
    {
        $user = $this->access->user($request);
        abort_unless(in_array($type, ['user', 'therapist']), 404);
        abort_if($type === 'user' && $id === $user->id, 422);
        $key = ['user_id' => $user->id, 'target_type' => $type, 'target_id' => $id];
        if ($request->isMethod('delete')) {
            DB::table('community_mutes')->where($key)->delete();
        } else {
            abort_unless(($type === 'user' ? User::whereKey($id) : Therapist::whereKey($id))->exists(), 404);
            DB::table('community_mutes')->insertOrIgnore($key + ['created_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    public function media(Request $request, string $media)
    {
        $user = $this->access->user($request);
        $file = DB::table('community_media')->where('id', $media)->first();
        abort_unless($file, 404);
        $this->access->post($user, $file->post_id);

        return $this->mediaResponse($file);
    }

    public function adminMedia(string $media)
    {
        $file = DB::table('community_media')->where('id', $media)->first();
        abort_unless($file, 404);

        return $this->mediaResponse($file);
    }

    private function mediaResponse(object $file)
    {
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return response()->file(Storage::disk('local')->path($file->path), [
            'Content-Type' => $file->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function presentPost(CommunityPost $post, User $user): array
    {
        $replies = $this->access->visible(CommunityReply::where('post_id', $post->id), $user, true);
        $expert = (clone $replies)->where('author_is_expert', true)->orderByDesc('created_at')->first();

        return $this->presentContent($post, $user) + [
            'category' => $post->category?->only(['id', 'label', 'slug']), 'tags' => $post->tags()->get(['community_tags.id', 'label']),
            'bookmarked' => DB::table('community_bookmarks')->where(['user_id' => $user->id, 'post_id' => $post->id])->exists(),
            'repliesCount' => (clone $replies)->count(), 'expertReply' => $expert ? $this->presentReply($expert, $user) : null,
            'locked' => $post->moderation_status === 'locked', 'pinned' => $post->moderation_status === 'pinned',
            'media' => DB::table('community_media')->where('post_id', $post->id)->orderBy('order')->get()->map(fn ($m) => [
                'id' => $m->id, 'type' => str_starts_with($m->mime_type, 'video/') ? 'video' : 'image',
                'path' => '/api/community/media/'.$m->id, 'mimeType' => $m->mime_type,
            ]),
        ];
    }

    private function presentReply(CommunityReply $reply, User $user): array
    {
        $parent = $reply->parent_reply_id ? $this->access->visible(CommunityReply::query(), $user, true)->where('post_id', $reply->post_id)->whereKey($reply->parent_reply_id)->first() : null;

        return $this->presentContent($reply, $user) + [
            'parentReplyId' => $reply->parent_reply_id,
            'parentReply' => $parent ? ['id' => $parent->id, 'authorName' => $parent->author_name, 'body' => Str::limit($parent->body, 180)] : null,
        ];
    }

    private function presentContent(CommunityPost|CommunityReply $item, User $user): array
    {
        return [
            'id' => $item->id, 'body' => $item->body, 'authorUserId' => $item->author_user_id,
            'authorTherapistId' => $item->author_therapist_id, 'authorName' => $item->author_name ?? 'Verwijderde gebruiker',
            'authorInitial' => $item->author_initial ?: '?', 'authorIsExpert' => (bool) $item->author_is_expert,
            'createdAt' => $item->created_at?->toISOString(), 'editedAt' => $item->edited_at?->toISOString(),
            'likesCount' => $item->reactions()->where('kind', 'like')->count(),
            'liked' => $item->reactions()->where('kind', 'like')->where('user_id', $user->id)->exists(),
            'owned' => $item->author_user_id === $user->id,
        ];
    }
}
