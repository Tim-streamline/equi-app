<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subscription_id',
    'date',
    'date_label',
    'amount_cents',
    'amount_label',
    'currency',
    'status',
    'receipt_url',
    'order',
])]
class Payment extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount_cents' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
