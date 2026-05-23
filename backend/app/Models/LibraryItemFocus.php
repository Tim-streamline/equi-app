<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['item_id', 'focus_topic_id'])]
class LibraryItemFocus extends Model
{
    use HasUuids;
    protected $table = 'library_item_focus';

    public function item(): BelongsTo
    {
        return $this->belongsTo(LibraryItem::class, 'item_id');
    }

    public function focusTopic(): BelongsTo
    {
        return $this->belongsTo(FocusTopic::class);
    }
}
