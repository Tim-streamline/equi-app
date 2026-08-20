<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['supplement_id', 'protocol_type_phase_week_id'])]
class SupplementWeek extends Pivot
{
    use HasUuids;

    public $incrementing = false;

    protected $table = 'supplement_weeks';

    public function supplement(): BelongsTo
    {
        return $this->belongsTo(Supplement::class);
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(ProtocolTypePhaseWeek::class, 'protocol_type_phase_week_id');
    }
}
