<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'library_item_id', 'uploaded_by', 'type', 'disk', 'path', 'url',
    'original_name', 'mime_type', 'size_bytes', 'thumbnail_path', 'thumbnail_url', 'width', 'height',
])]
class MediaAsset extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function libraryItem(): BelongsTo
    {
        return $this->belongsTo(LibraryItem::class);
    }

    /** Remove the backing file from disk; called before the row is deleted. */
    public function deleteFile(): void
    {
        Storage::disk($this->disk)->delete(array_filter([$this->path, $this->thumbnail_path]));
        LibraryItem::where('hero_image_url', $this->thumbnail_url ?: $this->url)->update(['hero_image_url' => null]);
    }
}
