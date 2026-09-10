<?php

namespace App\Support;

use App\Models\LibraryItem;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class LibraryThumbnail
{
    /** Process only the local upload, never fetch user-supplied URLs. */
    public function generate(MediaAsset $asset): void
    {
        if ($asset->type !== 'video' || $asset->thumbnail_url) {
            return;
        }

        $disk = Storage::disk($asset->disk);
        $path = 'library/thumbnails/'.Str::uuid().'.jpg';
        try {
            $disk->makeDirectory('library/thumbnails');
            // Limit the INPUT to one second, then choose a representative frame
            // from that batch. This also works for clips shorter than one second.
            (new Process([
                config('media.ffmpeg_binary', 'ffmpeg'), '-nostdin', '-y', '-v', 'error',
                '-protocol_whitelist', 'file,pipe', '-t', '1', '-i', $disk->path($asset->path),
                '-an', '-vf', 'scale=640:480:force_original_aspect_ratio=increase,crop=640:480,setsar=1,thumbnail=100',
                '-frames:v', '1', '-q:v', '3', $disk->path($path),
            ]))->setTimeout(30)->mustRun();
            if (! $disk->exists($path) || ! @getimagesize($disk->path($path))) {
                throw new \RuntimeException('No video frame was produced.');
            }
            $asset->update(['thumbnail_path' => $path, 'thumbnail_url' => $disk->url($path)]);
        } catch (Throwable $exception) {
            $disk->delete($path);
            Log::warning('Video thumbnail generation failed; using placeholder.', [
                'media_asset_id' => $asset->id, 'reason' => $exception->getMessage(),
            ]);
        }

        // A conditional update cannot overwrite a concurrently saved manual cover.
        if ($asset->library_item_id && $asset->thumbnail_url) {
            LibraryItem::whereKey($asset->library_item_id)->where('thumbnail_mode', 'auto')
                ->whereIn('format', ['video', 'course', 'program'])
                ->whereNull('hero_image_url')->update(['hero_image_url' => $asset->thumbnail_url]);
        }
    }

    public function resolve(LibraryItem $item): void
    {
        if ($item->thumbnail_mode === 'none') {
            $item->hero_image_url = null;
        } elseif ($item->thumbnail_mode === 'auto') {
            $item->hero_image_url = null;
            if (in_array($item->format, ['video', 'course', 'program'], true)) {
                $asset = $this->sourceAsset($item);
                if ($asset) {
                    $this->generate($asset);
                    $item->hero_image_url = $asset->thumbnail_url;
                }
            }
        }
        $item->save();
    }

    public function sourceAsset(LibraryItem $item): ?MediaAsset
    {
        // Embedded videos may predate the item and have no library_item_id.
        preg_match('/<video\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $item->body ?? '', $match);

        return isset($match[1])
            ? MediaAsset::where('type', 'video')->where('url', html_entity_decode($match[1]))->first()
            : $item->media()->where('type', 'video')->reorder()->oldest()->first();
    }
}
