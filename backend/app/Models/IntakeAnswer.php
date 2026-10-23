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

    protected static function booted(): void
    {
        static::saving(function (self $answer): void {
            $field = IntakeField::where('key', $answer->field_id)->whereHas('section', fn ($q) => $q->where('key', $answer->section_id))->first();
            if ($field && in_array($field->type, ['month_year', 'repeater'], true)) {
                $value = json_decode($answer->value ?? 'null', true);
                $answer->value = json_encode(app(\App\Support\IntakeDates::class)->forField($field, $value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
        });
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(IntakeBooking::class, 'response_id');
    }
}
