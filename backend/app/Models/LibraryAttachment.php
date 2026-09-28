<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'name', 'path', 'size_bytes', 'order'])]
#[Hidden(['path'])]
class LibraryAttachment extends Model
{
    use HasUuids;

    public function libraryItem(): BelongsTo
    {
        return $this->belongsTo(LibraryItem::class);
    }
}
