<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['slug', 'label', 'order', 'is_default', 'is_quick_filter', 'group_id', 'active'])]
class LibraryCategory extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'is_default' => 'boolean',
            'is_quick_filter' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(LibraryFilterGroup::class, 'group_id');
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(LibraryItem::class, 'library_item_categories', 'category_id', 'item_id')
            ->withTimestamps();
    }
}
