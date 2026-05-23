<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'icon', 'title', 'description', 'order'])]
class FocusTopic extends Model
{
    use HasUuids;
    public function horses(): BelongsToMany
    {
        return $this->belongsToMany(Horse::class, 'horse_focus')
            ->withPivot('extra_label', 'added_at')
            ->withTimestamps();
    }

    public function libraryItems(): BelongsToMany
    {
        return $this->belongsToMany(LibraryItem::class, 'library_item_focus', 'focus_topic_id', 'item_id')
            ->withTimestamps();
    }

    public function horseFocus(): HasMany
    {
        return $this->hasMany(HorseFocus::class);
    }
}
