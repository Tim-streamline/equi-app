<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'disclaimer_short', 'disclaimer_long', 'none_options', 'active'])]
class IntakeQuestionnaire extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'none_options' => 'array',
            'active' => 'boolean',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(IntakeSection::class, 'questionnaire_id')->orderBy('order');
    }
}
