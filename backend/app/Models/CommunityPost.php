<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'author_user_id',
    'author_name',
    'author_initial',
    'author_avatar_color',
    'body',
    'when_label',
    'likes_count',
    'replies_count',
    'has_expert_reply',
    'category_id',
    'order',
    'moderation_status',
    'reviewed_at',
])]
class CommunityPost extends Model
{
    use HasUuids;
    protected function casts(): array
    {
        return [
            'has_expert_reply' => 'boolean',
            'likes_count' => 'integer',
            'replies_count' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CommunityCategory::class, 'category_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CommunityTag::class, 'community_post_tags', 'post_id', 'tag_id')
            ->withTimestamps();
    }

    public function replies(): HasMany
    {
        return $this->hasMany(CommunityReply::class, 'post_id')->orderBy('order');
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(CommunityReaction::class, 'target');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ModerationReport::class, 'subject_id')->where('subject_type', 'post');
    }
}
