<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'protocol_template_id',
    'order',
    'name',
    'description',
    'required',
    'start_after_previous_phase_weeks',
])]
class ProtocolTemplatePhase extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'start_after_previous_phase_weeks' => 'integer',
        ];
    }

    public function protocolTemplate(): BelongsTo
    {
        return $this->belongsTo(ProtocolTemplate::class);
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(ProtocolTemplatePhaseWeek::class)->orderBy('number');
    }

    public function supplements(): HasMany
    {
        return $this->hasMany(Supplement::class)->orderBy('name');
    }

    public function protocolPhases(): HasMany
    {
        return $this->hasMany(ProtocolPhase::class);
    }
}
