<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    protected static function booted(): void
    {
        // The horse's own protocols and intakes cascade with it. Copies on other horses (of test users) that use
        // one of its intakes do not, and the database keeps an intake as long as a protocol uses it. Also for a
        // delete that arrives through the app's sync.
        // No return value: a value would stop the other listeners of this event.
        static::deleting(function (Horse $horse): void {
            Protocol::query()->where('horse_id', '!=', $horse->id)
                ->whereIn('intake_booking_id', IntakeBooking::where('horse_id', $horse->id)->select('id'))->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'laminitis_safety' => 'array',
            'age' => 'integer',
            'weight_kg' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
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

    public function supplementIntakes(): HasMany
    {
        return $this->hasMany(ProtocolSupplementIntake::class);
    }

    public function safetyReports(): HasMany
    {
        return $this->hasMany(IntakeSafetyReport::class);
    }
}
