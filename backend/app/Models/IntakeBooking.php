<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    protected $appends = ['submitted_at_label', 'status_label'];

    public function getSubmittedAtLabelAttribute(): string
    {
        return $this->submitted_at?->copy()->timezone('Europe/Amsterdam')->format('d-m-Y H:i')
            ?? ($this->intake_status === 'submitted' ? 'Datum onbekend' : 'Nog niet ingediend');
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status === 'pending' ? 'Te beoordelen' : ($this->status ?? '');
    }

    public function intakeHorseName(): string
    {
        $answer = $this->relationLoaded('answers')
            ? $this->answers->first(fn ($answer) => $answer->section_id === 'paard' && $answer->field_id === 'naam')?->value
            : $this->answers()->where('section_id', 'paard')->where('field_id', 'naam')->value('value');
        $name = json_decode($answer ?? 'null', true);
        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        return trim($this->horse?->name ?? '') ?: 'paard';
    }

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'answers_email_sent_at' => 'datetime', 'answers_email_claimed_at' => 'datetime',
            'submission_email_sent_at' => 'datetime', 'submission_email_claimed_at' => 'datetime',
            'started_at' => 'datetime', 'submitted_at' => 'datetime',
            'accepted_triggers' => 'array', 'review_updated_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    public function answers(): HasMany
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
