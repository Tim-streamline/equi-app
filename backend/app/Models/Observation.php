<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'horse_id',
    'author_id',
    'date',
    'note',
    'mood',
    'stool_score',
    'protocol_task_id',
])]
class Observation extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'mood' => 'integer',
        ];
    }

    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function protocolTask(): BelongsTo
    {
        return $this->belongsTo(ProtocolTask::class, 'protocol_task_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ObservationPhoto::class);
    }
}
