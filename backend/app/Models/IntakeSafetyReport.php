<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Server-owned report; state retains the original rule and recovery evidence. */
class IntakeSafetyReport extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['state' => 'array'];
    }

    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(IntakeBooking::class, 'intake_booking_id');
    }
}
