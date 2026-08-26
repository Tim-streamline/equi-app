<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'protocol_phase_supplement_id',
    'horse_id',
    'date',
    'dosage',
    'done',
    'taken_at',
])]
class ProtocolSupplementIntake extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'done' => 'boolean',
            'taken_at' => 'datetime',
        ];
    }

    public function protocolPhaseSupplement(): BelongsTo
    {
        return $this->belongsTo(ProtocolPhaseSupplement::class);
    }

    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
    }
}
