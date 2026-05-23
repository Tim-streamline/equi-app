<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'horse_id', 'date', 'done', 'done_at'])]
class ProtocolTaskCompletion extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'done' => 'boolean',
            'done_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProtocolTask::class, 'task_id');
    }

    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
    }
}
