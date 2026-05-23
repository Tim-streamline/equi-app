<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'scan_id',
    'ingredient_id',
    'name',
    'tag',
    'description',
    'order',
])]
class ScanIngredient extends Model
{
    use HasUuids;
    public function scan(): BelongsTo
    {
        return $this->belongsTo(ScanResult::class, 'scan_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
