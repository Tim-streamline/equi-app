<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'horse_id',
    'measured_at',
    'weight_kg',
    'energy',
    'stool_score',
    'label',
    'value_label',
    'trend',
    'order',
])]
class HorseStat extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'measured_at' => 'date',
            'weight_kg' => 'integer',
            'energy' => 'integer',
        ];
    }

    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
    }
}
