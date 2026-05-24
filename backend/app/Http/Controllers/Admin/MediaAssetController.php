<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Upload + manage library media (images, video, audio). Uploads return JSON
 * so the article editor can append the asset without an Inertia navigation
 * (which would discard unsaved body edits). The embedded reference in the
 * article body uses the asset's public URL, so a file works regardless of
 * which item it was uploaded under.
 */
class MediaAssetController extends Controller
{
    /** Accepted MIME types per media kind, with a per-kind max size in KB. */
    private const RULES = [
        'image' => ['mimes' => 'jpg,jpeg,png,gif,webp,svg', 'max' => 10240],   // 10 MB
        'audio' => ['mimes' => 'mp3,wav,ogg,m4a,aac', 'max' => 30720],          // 30 MB
        'video' => ['mimes' => 'mp4,webm,mov,m4v', 'max' => 153600],            // 150 MB
    ];

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file'],
            'library_item_id' => ['nullable', 'exists:library_items,id'],
        ]);

        $file = $request->file('file');
        $type = $this->detectType($file->getMimeType());

        abort_if($type === null, 422, 'Unsupported file type.');

        // Re-validate against the per-type extension + size allow-list.
        $request->validate([
            'file' => ['file', 'mimes:'.self::RULES[$type]['mimes'], 'max:'.self::RULES[$type]['max']],
        ]);

        $ext = $file->getClientOriginalExtension() ?: $file->guessExtension();
        $path = $file->storeAs('library/'.$type, Str::uuid().'.'.$ext, 'public');

        [$width, $height] = $this->dimensions($type, Storage::disk('public')->path($path));

        $asset = MediaAsset::create([
            'library_item_id' => $request->input('library_item_id'),
            'uploaded_by' => $request->user('admin')?->id,
            'type' => $type,
            'disk' => 'public',
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'width' => $width,
            'height' => $height,
        ]);

        AuditLogger::log('created', $asset, after: $asset->getAttributes(), label: $asset->original_name);

        return response()->json(['asset' => $asset]);
    }

    public function destroy(Request $request, MediaAsset $medium): RedirectResponse|JsonResponse
    {
        AuditLogger::log('deleted', $medium, before: $medium->getAttributes(), label: $medium->original_name);
        $medium->deleteFile();
        $medium->delete();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Media file removed.');
    }

    private function detectType(?string $mime): ?string
    {
        return match (true) {
            str_starts_with((string) $mime, 'image/') => 'image',
            str_starts_with((string) $mime, 'audio/') => 'audio',
            str_starts_with((string) $mime, 'video/') => 'video',
            default => null,
        };
    }

    /** @return array{0:?int,1:?int} */
    private function dimensions(string $type, string $absolutePath): array
    {
        if ($type === 'image' && function_exists('getimagesize')) {
            $info = @getimagesize($absolutePath);
            if ($info) {
                return [$info[0], $info[1]];
            }
        }

        return [null, null];
    }
}
