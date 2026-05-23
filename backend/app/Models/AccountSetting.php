<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'icon_key', 'title', 'subtitle', 'route', 'order'])]
class AccountSetting extends Model
{
    use HasUuids;
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
