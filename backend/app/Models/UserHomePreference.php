<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['seasonal_tips_enabled', 'dismissed_tip_ids'])]
class UserHomePreference extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['seasonal_tips_enabled' => 'boolean', 'dismissed_tip_ids' => 'array'];
    }
}
