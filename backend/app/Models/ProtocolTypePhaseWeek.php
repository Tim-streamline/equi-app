<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['protocol_type_phase_id', 'number'])]
class ProtocolTypePhaseWeek extends Model
{
    use HasUuids;

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProtocolTypePhase::class, 'protocol_type_phase_id');
    }

    public function supplements(): BelongsToMany
    {
        return $this->belongsToMany(
            Supplement::class,
            'supplement_weeks',
            'protocol_type_phase_week_id',
            'supplement_id',
        )->using(SupplementWeek::class)->withTimestamps()->orderBy('name');
    }
}
