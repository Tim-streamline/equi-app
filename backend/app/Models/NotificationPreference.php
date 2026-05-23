<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'reminder_protocol',
    'reminder_community',
    'reminder_seasonal_tips',
    'active_reminders_count',
    'push_token',
])]
class NotificationPreference extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'reminder_protocol' => 'boolean',
            'reminder_community' => 'boolean',
            'reminder_seasonal_tips' => 'boolean',
            'active_reminders_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
