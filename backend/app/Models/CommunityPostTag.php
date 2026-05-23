<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['post_id', 'tag_id', 'order'])]
class CommunityPostTag extends Model
{
    use HasUuids;
    public function post(): BelongsTo
    {
        return $this->belongsTo(CommunityPost::class, 'post_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(CommunityTag::class, 'tag_id');
    }
}
