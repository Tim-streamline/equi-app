<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'label', 'order', 'active'])]
class LibraryFilterGroup extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['active' => 'boolean', 'order' => 'integer'];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(LibraryCategory::class, 'group_id')->orderBy('order')->orderBy('label');
    }
}
