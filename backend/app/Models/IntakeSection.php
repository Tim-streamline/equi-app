<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['questionnaire_id', 'key', 'order', 'title', 'intro', 'minutes', 'icon', 'subtitle', 'active'])]
class IntakeSection extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'minutes' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(IntakeQuestionnaire::class, 'questionnaire_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(IntakeField::class, 'section_id')->orderBy('order');
    }
}
