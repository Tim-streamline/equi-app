<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'customer_settings',
    'horse_id',
    'protocol_template_id',
    'protocol_template_name',
    'therapist_id',
    'title',
    'subtitle_analyse',
    'subtitle_protocol',
    'subtitle_calendar',
    'total_weeks',
    'current_week',
    'started_at',
    'status',
    'published_at',
])]
class Protocol extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'customer_settings' => 'array',
            'started_at' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
    }

    public function protocolTemplate(): BelongsTo
    {
        return $this->belongsTo(ProtocolTemplate::class);
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class);
    }

    public function phases(): HasMany
    {
        return $this->hasMany(ProtocolPhase::class)->orderBy('order');
    }

    public function currentPhase(): HasOne
    {
        return $this->hasOne(ProtocolPhase::class)
            ->where('state', 'active')
            ->orderBy('order');
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(ProtocolAnalysis::class);
    }

    public function voedingAdviezen(): HasMany
    {
        return $this->hasMany(ProtocolVoedingAdvies::class)->orderBy('title');
    }

    public function managementAdviezen(): HasMany
    {
        return $this->hasMany(ProtocolManagementAdvies::class)->orderBy('title');
    }

    public function bewegingAdviezen(): HasMany
    {
        return $this->hasMany(ProtocolBewegingAdvies::class)->orderBy('title');
    }
}
