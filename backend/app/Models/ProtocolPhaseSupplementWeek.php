<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['protocol_phase_supplement_id', 'protocol_phase_week_id'])]
class ProtocolPhaseSupplementWeek extends Model
{
    use HasUuids;

    public function protocolPhaseSupplement(): BelongsTo
    {
        return $this->belongsTo(ProtocolPhaseSupplement::class);
    }

    public function protocolPhaseWeek(): BelongsTo
    {
        return $this->belongsTo(ProtocolPhaseWeek::class);
    }
}
