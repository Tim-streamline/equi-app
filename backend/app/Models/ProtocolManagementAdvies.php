<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['protocol_id', 'management_advies_id', 'title', 'description', 'layout'])]
class ProtocolManagementAdvies extends Model
{
    use HasUuids;

    protected $table = 'protocol_management_adviezen';

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    public function managementAdvies(): BelongsTo
    {
        return $this->belongsTo(ManagementAdvies::class);
    }
}
