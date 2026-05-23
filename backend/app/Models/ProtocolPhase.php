<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'protocol_id',
    'order',
    'title',
    'state',
    'week_start',
    'week_end',
    'chip_label',
])]
class ProtocolPhase extends Model
{
    use HasUuids;
    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProtocolPhaseItem::class, 'phase_id')->orderBy('order');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProtocolTask::class, 'phase_id');
    }
}
