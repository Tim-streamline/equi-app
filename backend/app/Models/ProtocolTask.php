<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'protocol_id',
    'phase_id',
    'label',
    'meta',
    'kind',
    'order',
    'active_from',
    'active_until',
    'reference_item_id',
])]
class ProtocolTask extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'active_from' => 'date',
            'active_until' => 'date',
        ];
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProtocolPhase::class, 'phase_id');
    }

    public function referenceItem(): BelongsTo
    {
        return $this->belongsTo(LibraryItem::class, 'reference_item_id');
    }

    public function completions(): HasMany
    {
        return $this->hasMany(ProtocolTaskCompletion::class, 'task_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class, 'protocol_task_id');
    }
}
