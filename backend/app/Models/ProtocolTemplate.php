<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class ProtocolTemplate extends Model
{
    use HasUuids;

    public function phases(): HasMany
    {
        return $this->hasMany(ProtocolTemplatePhase::class)->orderBy('order');
    }

    public function protocols(): HasMany
    {
        return $this->hasMany(Protocol::class);
    }
}
