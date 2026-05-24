<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'password',
    'role',
    'active',
    'email_verified_at',
])]
#[Hidden(['password', 'remember_token'])]
class AdminUser extends Authenticatable
{
    use HasUuids, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    /**
     * Role scopes recognised by the admin console, in descending privilege.
     * `owner` is a super-role that implies every other scope.
     */
    public const ROLES = [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'content_editor' => 'Content editor',
        'moderator' => 'Community moderator',
        'therapist_admin' => 'Therapist',
        'support' => 'Support',
        'billing' => 'Billing support',
    ];

    public function hasRole(string ...$roles): bool
    {
        return $this->role === 'owner' || in_array($this->role, $roles, true);
    }
}
