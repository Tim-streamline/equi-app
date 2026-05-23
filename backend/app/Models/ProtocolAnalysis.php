<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['protocol_id', 'cause'])]
class ProtocolAnalysis extends Model
{
    use HasUuids;
    protected $table = 'protocol_analyses';

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    public function advice(): HasMany
    {
        return $this->hasMany(ProtocolAdvice::class, 'analysis_id')->orderBy('order');
    }
}
