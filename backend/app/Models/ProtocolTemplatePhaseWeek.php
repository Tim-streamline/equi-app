<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['protocol_template_phase_id', 'number'])]
class ProtocolTemplatePhaseWeek extends Model
{
    use HasUuids;

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProtocolTemplatePhase::class, 'protocol_template_phase_id');
    }

    public function supplements(): BelongsToMany
    {
        return $this->belongsToMany(
            Supplement::class,
            'supplement_weeks',
            'protocol_template_phase_week_id',
            'supplement_id',
        )->using(SupplementWeek::class)->withTimestamps()->orderBy('name');
    }
}
