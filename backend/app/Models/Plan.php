<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug',
    'label',
    'name',
    'price_cents',
    'currency',
    'interval',
    'price_suffix',
    'description',
    'is_recommended',
    'order',
])]
class Plan extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'is_recommended' => 'boolean',
        ];
    }

    public function benefits(): HasMany
    {
        return $this->hasMany(PlanBenefit::class)->orderBy('order');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
