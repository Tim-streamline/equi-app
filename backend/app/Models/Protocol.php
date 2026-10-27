<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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
    'archived_previous_status',
    'published_at',
])]
class Protocol extends Model
{
    use HasUuids;

    /** Internal creation path used while an intake is creating its draft protocol. */
    public bool $creatingForIntake = false;

    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            $new = ! $this->exists;
            if ($new || $this->isDirty(['status', 'published_at', 'horse_id'])) \App\Support\LibrarySyncLock::acquire();
            if (! $new && $this->isDirty('horse_id')) {
                throw ValidationException::withMessages(['horse_id' => 'Een protocol en zijn intake blijven bij hetzelfde paard.']);
            }
            // Serialize competing activations, including restore and direct model writes.
            Horse::whereKey($this->horse_id)->lockForUpdate()->firstOrFail();
            if (($this->status ?? 'active') === 'active' && static::where('horse_id', $this->horse_id)
                ->where('status', 'active')->when($this->getKey(), fn ($q, $id) => $q->whereKeyNot($id))->exists()) {
                throw ValidationException::withMessages(['status' => 'Dit paard heeft al een actief protocol. Rond dat eerst af of pauzeer het.']);
            }
            $saved = parent::save($options);
            if ($saved && $new && ! $this->creatingForIntake) {
                $this->intake()->create(['horse_id' => $this->horse_id, 'user_id' => $this->horse->owner_id,
                    'status' => 'pending', 'intake_status' => 'draft']);
            }

            return $saved;
        });
    }

    /** Preparing a protocol requires its own submitted intake; answer rules run at submission. */
    public function assertIntakeSubmitted(): void
    {
        if (! $this->exists || ! $this->intake()->whereNotNull('submitted_at')->where('status', '!=', 'cancelled')->exists()) {
            throw ValidationException::withMessages(['intake' => 'De bijbehorende intake moet eerst worden verstuurd voordat je het protocol kunt opstellen.']);
        }
    }

    public function delete()
    {
        return DB::transaction(function () {
            \App\Support\LibrarySyncLock::acquire();
            static::whereKey($this->id)->lockForUpdate()->firstOrFail();
            $paths = IntakeAttachment::whereIn('booking_id', $this->intake()->select('id'))->pluck('path')->all();
            $deleted = parent::delete();
            if ($deleted && $paths) {
                DB::afterCommit(function () use ($paths) {
                    try {
                        if (! Storage::disk('local')->delete($paths)) {
                            throw new \RuntimeException('Could not clean up protocol intake attachments.');
                        }
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                });
            }

            return $deleted;
        });
    }

    public function intake(): HasOne
    {
        return $this->hasOne(IntakeBooking::class);
    }

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
