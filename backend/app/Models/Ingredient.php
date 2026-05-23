<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'default_tag'])]
class Ingredient extends Model
{
    use HasUuids;
    public function scanIngredients(): HasMany
    {
        return $this->hasMany(ScanIngredient::class);
    }
}
