<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'label', 'order', 'is_default'])]
class CommunityCategory extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(CommunityPost::class, 'category_id');
    }
}
