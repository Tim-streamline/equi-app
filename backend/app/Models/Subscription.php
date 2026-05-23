<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'plan_id',
    'status',
    'price_cents',
    'currency',
    'interval',
    'started_at',
    'started_label',
    'renews_at',
    'renews_label',
    'cancelled_at',
    'max_horses',
])]
class Subscription extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'max_horses' => 'integer',
            'started_at' => 'date',
            'renews_at' => 'date',
            'cancelled_at' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('date');
    }
}
