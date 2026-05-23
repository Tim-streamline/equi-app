<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'item_id',
    'position_sec',
    'progress',
    'completed',
    'last_viewed_at',
])]
class LibraryProgress extends Model
{
    use HasUuids;
    protected $table = 'library_progress';

    protected function casts(): array
    {
        return [
            'position_sec' => 'integer',
            'progress' => 'float',
            'completed' => 'boolean',
            'last_viewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LibraryItem::class, 'item_id');
    }
}
