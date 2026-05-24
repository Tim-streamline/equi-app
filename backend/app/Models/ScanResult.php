<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'horse_id',
    'product_id',
    'product_name',
    'brand',
    'scanned_at',
    'when_label',
    'score',
    'rating',
    'advice',
    'photo_url',
    'bookmarked',
    'flagged',
])]
class ScanResult extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'score' => 'integer',
            'bookmarked' => 'boolean',
            'flagged' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(ScanIngredient::class, 'scan_id')->orderBy('order');
    }
}
