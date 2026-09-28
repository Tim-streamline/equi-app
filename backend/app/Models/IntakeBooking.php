<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'horse_id',
    'therapist_id',
    'scheduled_at',
    'slot_label',
    'duration_minutes',
    'status',
    'notes',
    'intake_status', 'started_at', 'submitted_at', 'accepted_triggers', 'review_notes', 'review_updated_at',
])]
class IntakeBooking extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime', 'submitted_at' => 'datetime',
            'accepted_triggers' => 'array', 'review_updated_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    public function answers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IntakeAnswer::class, 'response_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class);
    }
}
