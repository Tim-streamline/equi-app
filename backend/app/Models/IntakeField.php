<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'section_id',
    'key',
    'order',
    'label',
    'type',
    'hint',
    'required',
    'optional',
    'unit',
    'step',
    'tall',
    'lines',
    'placeholder',
    'link',
    'options',
    'show_if',
    'flag_if',
    'critical_if',
    'protocol_if',
    'repeater_sub',
    'active',
])]
class IntakeField extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'required' => 'boolean',
            'optional' => 'boolean',
            'step' => 'float',
            'tall' => 'boolean',
            'lines' => 'integer',
            'link' => 'array',
            'options' => 'array',
            'show_if' => 'array',
            'flag_if' => 'array',
            'critical_if' => 'array',
            'protocol_if' => 'array',
            'repeater_sub' => 'array',
            'active' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(IntakeSection::class, 'section_id');
    }
}
