<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'protocol_id',
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

    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            if ($this->exists && ($this->isDirty('protocol_id') || $this->isDirty('horse_id') || $this->isDirty('user_id'))) {
                throw ValidationException::withMessages(['protocol_id' => 'Een intake kan niet naar een ander protocol, paard of account worden verplaatst.']);
            }
            if (! $this->exists && ! $this->protocol_id) {
                $protocol = new Protocol(['horse_id' => $this->horse_id, 'title' => 'Intakeprotocol', 'status' => 'draft']);
                $protocol->creatingForIntake = true;
                $protocol->save();
                $this->protocol_id = $protocol->id;
            }

            return parent::save($options);
        });
    }

    public function delete()
    {
        // A configured protocol must retain its intake. Delete the protocol explicitly instead.
        return DB::transaction(function () {
            $protocol = $this->protocol()->lockForUpdate()->firstOrFail();
            if ($protocol->status !== 'draft' || $protocol->protocol_template_id || $protocol->published_at) {
                throw ValidationException::withMessages(['booking_delete' => 'Deze intake hoort bij een protocol. Verwijder het protocol om ook de intake te verwijderen.']);
            }
            $deleted = $protocol->delete();
            $this->exists = false;

            return $deleted;
        });
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    protected $appends = ['submitted_at_label', 'status_label'];

    public function getSubmittedAtLabelAttribute(): string
    {
        return $this->submitted_at?->copy()->timezone('Europe/Amsterdam')->format('d-m-Y H:i')
            ?? 'Nog niet ingediend';
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->status === 'cancelled') {
            return 'Geannuleerd';
        }
        if (! $this->submitted_at) {
            return 'Nog niet ingediend';
        }

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
            'last_activity_at' => 'datetime', 'last_reminder_at' => 'datetime',
            'answers_email_sent_at' => 'datetime', 'answers_email_claimed_at' => 'datetime',
            'submission_email_sent_at' => 'datetime', 'submission_email_claimed_at' => 'datetime',
            'started_at' => 'datetime', 'submitted_at' => 'datetime', 'completed_at' => 'datetime',
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
