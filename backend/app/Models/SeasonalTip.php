<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'month',
    'month_order',
    'body',
    'cta_item_id',
    'active',
    'active_from',
    'active_to',
])]
class SeasonalTip extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'active_from' => 'date',
            'active_to' => 'date',
        ];
    }

    public function ctaItem(): BelongsTo
    {
        return $this->belongsTo(LibraryItem::class, 'cta_item_id');
    }
}
