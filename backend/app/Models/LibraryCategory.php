<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['slug', 'label', 'order', 'is_default'])]
class LibraryCategory extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(LibraryItem::class, 'library_item_categories', 'category_id', 'item_id')
            ->withTimestamps();
    }
}
