<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'protocol_id',
    'protocol_template_phase_id',
    'order',
    'title',
    'description',
    'required',
    'start_after_previous_phase_weeks',
    'state',
    'week_start',
    'week_end',
    'chip_label',
])]
class ProtocolPhase extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'start_after_previous_phase_weeks' => 'integer',
        ];
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProtocolTemplatePhase::class, 'protocol_template_phase_id');
    }

    public function supplements(): HasMany
    {
        return $this->hasMany(ProtocolPhaseSupplement::class);
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(ProtocolPhaseWeek::class)->orderBy('number');
    }
}
