<?php

namespace App\Models;

use App\Enums\SupplementDoseType;
use App\Enums\SupplementDoseUnit;
use App\Enums\SupplementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'protocol_phase_id',
    'supplement_id',
    'name',
    'description',
    'supplement_type',
    'dosis_type',
    'dosis',
    'unit',
    'add_by_default',
    'max_aantal_in_fase',
    'min_aantal_per_week',
    'rust_periode_in_weken',
    'dosage',
    'aantal_per_week',
    'instructions',
])]
class ProtocolPhaseSupplement extends Model
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
            'aantal_per_week' => 'integer',
        ];
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProtocolPhase::class, 'protocol_phase_id');
    }

    public function supplement(): BelongsTo
    {
        return $this->belongsTo(Supplement::class);
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(ProtocolPhaseSupplementWeek::class);
    }
}
