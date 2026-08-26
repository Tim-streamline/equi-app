<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['protocol_id', 'beweging_advies_id', 'title', 'description', 'layout'])]
class ProtocolBewegingAdvies extends Model
{
    use HasUuids;

    protected $table = 'protocol_beweging_adviezen';

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    public function bewegingAdvies(): BelongsTo
    {
        return $this->belongsTo(BewegingAdvies::class);
    }
}
