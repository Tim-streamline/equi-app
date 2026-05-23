<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['analysis_id', 'icon_key', 'title', 'body', 'order'])]
class ProtocolAdvice extends Model
{
    use HasUuids;
    protected $table = 'protocol_advice';

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(ProtocolAnalysis::class, 'analysis_id');
    }
}
