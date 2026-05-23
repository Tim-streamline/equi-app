<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'horse_id',
    'occurred_at',
    'when_label',
    'kind',
    'message',
    'ref_type',
    'ref_id',
    'is_now',
    'order',
])]
class TimelineEvent extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'is_now' => 'boolean',
        ];
    }

    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
    }
}
