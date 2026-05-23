<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['slug', 'label'])]
class CommunityTag extends Model
{
    use HasUuids;
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(CommunityPost::class, 'community_post_tags', 'tag_id', 'post_id')
            ->withTimestamps();
    }
}
