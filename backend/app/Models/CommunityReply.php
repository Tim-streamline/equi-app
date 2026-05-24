<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'post_id',
    'author_user_id',
    'author_therapist_id',
    'author_name',
    'author_initial',
    'author_avatar_color',
    'author_is_expert',
    'body',
    'when_label',
    'likes_count',
    'replies_count',
    'order',
    'moderation_status',
    'reviewed_at',
])]
class CommunityReply extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'author_is_expert' => 'boolean',
            'likes_count' => 'integer',
            'replies_count' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(CommunityPost::class, 'post_id');
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function authorTherapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class, 'author_therapist_id');
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(CommunityReaction::class, 'target');
    }
}
