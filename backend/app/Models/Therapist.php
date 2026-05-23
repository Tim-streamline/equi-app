<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'title',
    'bio',
    'avatar_url',
    'avatar_initial',
    'avatar_color',
    'verified',
])]
class Therapist extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
        ];
    }

    public function protocols(): HasMany
    {
        return $this->hasMany(Protocol::class);
    }

    public function authoredLibraryItems(): HasMany
    {
        return $this->hasMany(LibraryItem::class, 'author_therapist_id');
    }

    public function expertReplies(): HasMany
    {
        return $this->hasMany(CommunityReply::class, 'author_therapist_id');
    }

    public function horseShares(): HasMany
    {
        return $this->hasMany(HorseShare::class);
    }

    public function intakeBookings(): HasMany
    {
        return $this->hasMany(IntakeBooking::class);
    }
}
