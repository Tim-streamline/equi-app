<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'password',
    'avatar_initial',
    'avatar_url',
    'locale',
    'units_system',
    'notifications_on',
    'onboarded_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notifications_on' => 'boolean',
            'onboarded_at' => 'datetime',
        ];
    }

    public function horses(): HasMany
    {
        return $this->hasMany(Horse::class, 'owner_id');
    }

    public function sharedHorses(): HasMany
    {
        return $this->hasMany(HorseShare::class, 'grantee_user_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class, 'author_id');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(ScanResult::class);
    }

    public function libraryBookmarks(): HasMany
    {
        return $this->hasMany(LibraryBookmark::class);
    }

    public function libraryProgress(): HasMany
    {
        return $this->hasMany(LibraryProgress::class);
    }

    public function communityPosts(): HasMany
    {
        return $this->hasMany(CommunityPost::class, 'author_user_id');
    }

    public function communityReplies(): HasMany
    {
        return $this->hasMany(CommunityReply::class, 'author_user_id');
    }

    public function communityReactions(): HasMany
    {
        return $this->hasMany(CommunityReaction::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->where('status', 'active');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function notificationPreferences(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    public function accountSettings(): HasMany
    {
        return $this->hasMany(AccountSetting::class);
    }

    public function dataExports(): HasMany
    {
        return $this->hasMany(DataExport::class);
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    public function intakeBookings(): HasMany
    {
        return $this->hasMany(IntakeBooking::class);
    }
}
