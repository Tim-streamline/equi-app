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
    'option_effects',
    'option_keys',
    'visibility_rule',
    'answer_aliases',
    'submission_blocks',
    'show_if',
    'critical_if',
    'protocol_if',
    'signal_config',
    'repeater_sub',
    'active',
])]
class IntakeField extends Model
{
    use HasUuids;

    public bool $syncingDefinition = false;

    protected static function booted(): void
    {
        static::saving(fn (self $field) => app(\App\Support\IntakeAnswerConditions::class)->prepare($field));
        static::saved(function (self $field) {
            if (! $field->syncingDefinition && $field->getRawOriginal('option_keys') !== null
                && $field->wasChanged(['option_keys', 'answer_aliases', 'answer_section_key', 'section_id', 'key', 'active'])) {
                app(\App\Support\IntakeAnswerConditions::class)->refreshProjections($field);
            }
        });
    }

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
            'option_effects' => 'array',
            'option_keys' => 'array',
            'visibility_rule' => 'array',
            'answer_aliases' => 'array',
            'submission_blocks' => 'array',
            'show_if' => 'array',
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
