<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['booking_id', 'section_key', 'field_key', 'name', 'path', 'mime'])]
class IntakeAttachment extends Model
{
    use HasUuids;

    public function booking(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(IntakeBooking::class);
    }
}
