<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'section_id',
    'answer_section_key',
    'photo_config',
    'age_config',
    'date_config',
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
    'signal_config',
    'repeater_sub',
    'active',
])]
class IntakeField extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'photo_config' => 'array',
            'age_config' => 'array',
            'date_config' => 'array',
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
            'signal_config' => 'array',
            'repeater_sub' => 'array',
            'active' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(IntakeSection::class, 'section_id');
    }
}
