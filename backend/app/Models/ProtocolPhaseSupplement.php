<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['protocol_phase_id', 'supplement_id'])]
class ProtocolPhaseSupplement extends Model
{
    use HasUuids;

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProtocolPhase::class, 'protocol_phase_id');
    }

    public function supplement(): BelongsTo
    {
        return $this->belongsTo(Supplement::class);
    }
}
