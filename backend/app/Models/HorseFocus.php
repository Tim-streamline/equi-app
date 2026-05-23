<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['horse_id', 'focus_topic_id', 'extra_label', 'added_at'])]
class HorseFocus extends Model
{
    use HasUuids;
    protected $table = 'horse_focus';

    protected function casts(): array
    {
        return [
            'added_at' => 'datetime',
        ];
    }

    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
    }

    public function focusTopic(): BelongsTo
    {
        return $this->belongsTo(FocusTopic::class);
    }
}
