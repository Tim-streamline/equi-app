<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'owner_id',
    'name',
    'breed',
    'age',
    'sex',
    'weight_kg',
    'stable',
    'photo_url',
    'status',
    'archived_at',
    'archived_note',
])]
class Horse extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'weight_kg' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function focusTopics(): BelongsToMany
    {
        return $this->belongsToMany(FocusTopic::class, 'horse_focus')
            ->withPivot('extra_label', 'added_at')
            ->withTimestamps();
    }

    public function focus(): HasMany
    {
        return $this->hasMany(HorseFocus::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(HorseShare::class);
    }

    public function stats(): HasMany
    {
        return $this->hasMany(HorseStat::class);
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(TimelineEvent::class);
    }

    public function protocols(): HasMany
    {
        return $this->hasMany(Protocol::class);
    }

    public function activeProtocol(): HasOne
    {
        return $this->hasOne(Protocol::class)->where('status', 'active');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(ScanResult::class);
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    public function intakeBookings(): HasMany
    {
        return $this->hasMany(IntakeBooking::class);
    }

    public function taskCompletions(): HasMany
    {
        return $this->hasMany(ProtocolTaskCompletion::class);
    }
}
