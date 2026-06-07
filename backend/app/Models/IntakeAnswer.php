<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'response_id',
    'section_id',
    'field_id',
    'value',
])]
class IntakeAnswer extends Model
{
    use HasUuids;

    public function response(): BelongsTo
    {
        return $this->belongsTo(IntakeResponse::class, 'response_id');
    }
}
