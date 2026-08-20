<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'protocol_type_id',
    'order',
    'name',
    'description',
    'required',
])]
class ProtocolTypePhase extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
        ];
    }

    public function protocolType(): BelongsTo
    {
        return $this->belongsTo(ProtocolType::class);
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(ProtocolTypePhaseWeek::class)->orderBy('number');
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
