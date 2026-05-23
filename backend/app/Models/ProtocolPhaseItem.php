<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['phase_id', 'order', 'label'])]
class ProtocolPhaseItem extends Model
{
    use HasUuids;
    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProtocolPhase::class, 'phase_id');
    }
}
