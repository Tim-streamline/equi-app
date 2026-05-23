<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'horse_id',
    'therapist_id',
    'title',
    'subtitle_analyse',
    'subtitle_protocol',
    'subtitle_calendar',
    'total_weeks',
    'current_week',
    'started_at',
    'status',
])]
class Protocol extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'started_at' => 'date',
        ];
    }

    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class);
    }

    public function phases(): HasMany
    {
        return $this->hasMany(ProtocolPhase::class)->orderBy('order');
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(ProtocolAnalysis::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProtocolTask::class);
    }
}
