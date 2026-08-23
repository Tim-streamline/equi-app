<?php

namespace App\Models;

use App\Enums\SupplementDoseType;
use App\Enums\SupplementDoseUnit;
use App\Enums\SupplementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'protocol_template_phase_id',
    'name',
    'description',
    'instructions',
    'supplement_type',
    'dosis_type',
    'dosis',
    'unit',
    'add_by_default',
    'max_aantal_in_fase',
    'min_aantal_per_week',
    'rust_periode_in_weken',
])]
class Supplement extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'supplement_type' => SupplementType::class,
            'dosis_type' => SupplementDoseType::class,
            'dosis' => 'float',
            'unit' => SupplementDoseUnit::class,
            'add_by_default' => 'boolean',
            'max_aantal_in_fase' => 'integer',
            'min_aantal_per_week' => 'integer',
            'rust_periode_in_weken' => 'integer',
        ];
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProtocolTemplatePhase::class, 'protocol_template_phase_id');
    }

    public function weeks(): BelongsToMany
    {
        return $this->belongsToMany(
            ProtocolTemplatePhaseWeek::class,
            'supplement_weeks',
            'supplement_id',
            'protocol_template_phase_week_id',
        )->using(SupplementWeek::class)->withTimestamps()->orderBy('number');
    }

    public function protocolPhaseSupplements(): HasMany
    {
        return $this->hasMany(ProtocolPhaseSupplement::class);
    }
}
