<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subject_type', 'subject_id', 'reporter_user_id', 'reason', 'detail',
    'status', 'resolved_by', 'resolved_at',
])]
class ModerationReport extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function subject(): ?Model
    {
        return $this->subject_type === 'reply'
            ? CommunityReply::find($this->subject_id)
            : CommunityPost::find($this->subject_id);
    }
}
