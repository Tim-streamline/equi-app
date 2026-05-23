<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['horse_id', 'grantee_user_id', 'therapist_id', 'role', 'since'])]
class HorseShare extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'since' => 'date',
        ];
    }

    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
    }

    public function granteeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantee_user_id');
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class);
    }
}
