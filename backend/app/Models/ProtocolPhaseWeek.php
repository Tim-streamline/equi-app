<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'protocol_phase_id',
    'protocol_template_phase_week_id',
    'number',
    'protocol_week_number',
])]
class ProtocolPhaseWeek extends Model
{
    use HasUuids;

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProtocolPhase::class, 'protocol_phase_id');
    }

    public function templateWeek(): BelongsTo
    {
        return $this->belongsTo(ProtocolTemplatePhaseWeek::class, 'protocol_template_phase_week_id');
    }

    public function supplementWeeks(): HasMany
    {
        return $this->hasMany(ProtocolPhaseSupplementWeek::class);
    }
}
