<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['observation_id', 'url', 'caption', 'kind'])]
class ObservationPhoto extends Model
{
    use HasUuids;
    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }
}
