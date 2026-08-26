<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['protocol_id', 'voeding_advies_id', 'title', 'description', 'layout'])]
class ProtocolVoedingAdvies extends Model
{
    use HasUuids;

    protected $table = 'protocol_voeding_adviezen';

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    public function voedingAdvies(): BelongsTo
    {
        return $this->belongsTo(VoedingAdvies::class);
    }
}
