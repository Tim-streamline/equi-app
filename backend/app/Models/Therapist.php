<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

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
            'archived_at' => 'datetime',
        ];
    }

    // Explicit scopes keep archived names available through historical relations.
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeAvailableFor(Builder $query, ?string $currentId = null): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('archived_at')
            ->when($currentId, fn ($q) => $q->orWhere('id', $currentId)));
    }

    public static function assignmentRule(?string $currentId = null): Exists
    {
        return Rule::exists('therapists', 'id')->where(fn ($q) => $q
            ->where(fn ($q) => $q->whereNull('archived_at')
                ->when($currentId, fn ($q) => $q->orWhere('id', $currentId))));
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
