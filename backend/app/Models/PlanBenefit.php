<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['plan_id', 'label', 'order'])]
class PlanBenefit extends Model
{
    use HasUuids;
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
