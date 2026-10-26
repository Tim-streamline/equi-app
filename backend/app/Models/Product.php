<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['brand', 'name', 'barcode', 'category', 'needs_review'])]
class Product extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['needs_review' => 'boolean'];
    }

    public function relatedProduct(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'related_product_id');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(ScanResult::class);
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class)
            ->withPivot(['order', 'amount'])
            ->withTimestamps()
            ->orderByPivot('order');
    }
}
