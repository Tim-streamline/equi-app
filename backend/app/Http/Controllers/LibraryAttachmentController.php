<?php

namespace App\Http\Controllers;

use App\Models\{LibraryAttachment, LibraryItem, User};
use App\Support\LibraryDiscovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Storage, URL};

class LibraryAttachmentController extends Controller
{
    public function open(Request $request, LibraryItem $library, LibraryAttachment $attachment)
    {
        abort_unless($attachment->library_item_id === $library->id, 404);
        $user = User::findOrFail($request->attributes->get('powersync_user_id'));
        $this->authorizeRead($user, $library);

        // External PDF viewers cannot send the app's bearer token. This short-lived
        // capability is signed, and publication/account/access are checked again on use.
        return response()->json(['url' => URL::temporarySignedRoute('library.attachment', now()->addMinutes(2), [
            'attachment' => $attachment->id, 'user' => $user->id,
        ])])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, LibraryAttachment $attachment)
    {
        $user = User::findOrFail($request->query('user'));
        $this->authorizeRead($user, $attachment->libraryItem);

        return $this->file($attachment);
    }

    public function admin(LibraryAttachment $attachment)
    {
        return $this->file($attachment);
    }

    private function authorizeRead(User $user, LibraryItem $item): void
    {
        abort_if($user->disabled_at, 403);
        abort_unless($item->published_at && $item->published_at->lte(now()), 404);
        $discovery = app(LibraryDiscovery::class);
        abort_unless($discovery->canRead($item, $discovery->access($user)), 403);
    }

    private function file(LibraryAttachment $attachment)
    {
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->response($attachment->path, $attachment->name, [
            'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
