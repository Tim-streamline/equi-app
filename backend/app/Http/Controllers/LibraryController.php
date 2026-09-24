<?php

namespace App\Http\Controllers;

use App\Models\LibraryItem;
use App\Models\User;
use App\Support\LibraryDiscovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LibraryController extends Controller
{
    public function access(Request $request, LibraryDiscovery $discovery)
    {
        return response()->json($discovery->access($this->user($request)));
    }

    public function bookmarks(Request $request)
    {
        $user = $this->user($request);

        return response()->json(['itemIds' => DB::table('library_bookmarks')
            ->where('user_id', $user->id)->pluck('item_id')->all()]);
    }

    public function selection(Request $request, string $selection, LibraryDiscovery $discovery)
    {
        $user = $this->user($request);
        $slugs = config('library.selections.'.$selection);
        abort_unless(is_array($slugs) && count($slugs) === 2, 404);
        $items = LibraryItem::query()->whereIn('slug', $slugs)
            ->whereNotNull('published_at')->where('published_at', '<=', now())->get()->keyBy('slug');
        // A curated pair must never silently become a partial or unrelated selection.
        abort_unless($items->count() === 2, 503, 'Deze selectie is tijdelijk niet beschikbaar. Probeer het later opnieuw.');

        return response()->json([
            'items' => collect($slugs)->map(fn ($slug) => $discovery->summary($items->get($slug)))->values(),
            'access' => $discovery->access($user),
        ]);
    }

    public function related(Request $request, LibraryItem $library, LibraryDiscovery $discovery)
    {
        $user = $this->user($request);
        $this->published($library);

        return response()->json([
            'items' => $discovery->related($library)->map(fn ($item) => $discovery->summary($item)),
            'access' => $discovery->access($user),
        ]);
    }

    public function show(Request $request, LibraryItem $library, LibraryDiscovery $discovery)
    {
        $user = $this->user($request);
        $this->published($library);
        $access = $discovery->access($user);
        $canRead = $discovery->canRead($library, $access);

        return response()->json([
            'item' => $discovery->summary($library), 'access' => $access, 'canRead' => $canRead,
            'body' => $canRead ? $library->body : null,
            'chapters' => $canRead ? $library->chapters->map(fn ($chapter) => [
                'id' => $chapter->id, 'title' => $chapter->title, 'startLabel' => $chapter->start_label,
            ]) : [],
        ]);
    }

    public function unlock(Request $request, LibraryItem $library, LibraryDiscovery $discovery)
    {
        $user = $this->user($request);
        $request->validate(['credits' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($request, $user, $library, $discovery) {
            // Serialize spends and duplicate requests for this account, even before a balance row exists.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $item = LibraryItem::whereKey($library->id)->lockForUpdate()->firstOrFail();
            $this->published($item);
            $access = $discovery->access($user);
            if ($discovery->canRead($item, $access)) {
                return;
            }
            abort_if($item->is_plus, 403, 'Dit item is beschikbaar met Plus.');
            $cost = (int) $item->credit_cost;
            abort_if($request->integer('credits') !== $cost, 409, 'Het aantal benodigde credits is gewijzigd. Open het item opnieuw.');
            $balance = DB::table('library_credit_balances')->where('user_id', $user->id)->lockForUpdate()->first();
            abort_unless($balance && $balance->balance >= $cost, 422, 'Je hebt onvoldoende credits voor dit item.');
            DB::table('library_credit_balances')->where('user_id', $user->id)->decrement('balance', $cost, ['updated_at' => now()]);
            DB::table('library_unlocks')->insert(['user_id' => $user->id, 'item_id' => $item->id, 'created_at' => now(), 'updated_at' => now()]);
        });

        return $this->show($request, $library->fresh(), $discovery);
    }

    public function bookmark(Request $request, LibraryItem $library)
    {
        $user = $this->user($request);
        $key = ['user_id' => $user->id, 'item_id' => $library->id];
        if ($request->isMethod('delete')) {
            DB::table('library_bookmarks')->where($key)->delete();
        } else {
            $this->published($library);
            if ($request->isMethod('put')) {
                DB::table('library_bookmarks')->insertOrIgnore($key + [
                    'id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        return response()->json(['bookmarked' => DB::table('library_bookmarks')->where($key)->exists()]);
    }

    private function user(Request $request): User
    {
        $user = User::find($request->attributes->get('powersync_user_id'));
        abort_unless($user, 401);
        abort_if($user->disabled_at, 403);

        return $user;
    }

    private function published(LibraryItem $item): void
    {
        abort_unless($item->published_at && $item->published_at->lte(now()), 404);
    }
}
