<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug',
    'format',
    'title',
    'description',
    'body',
    'hero_image_url',
    'duration_label',
    'duration_sec',
    'author_therapist_id',
    'views_label',
    'published_at',
    'is_plus',
    'is_featured',
    'order',
])]
class LibraryItem extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'duration_sec' => 'integer',
            'is_plus' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Therapist::class, 'author_therapist_id');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(LibraryChapter::class, 'item_id')->orderBy('order');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(LibraryArticleSection::class, 'item_id')->orderBy('order');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(LibraryCategory::class, 'library_item_categories', 'item_id', 'category_id')
            ->withTimestamps();
    }

    public function focusTopics(): BelongsToMany
    {
        return $this->belongsToMany(FocusTopic::class, 'library_item_focus', 'item_id', 'focus_topic_id')
            ->withTimestamps();
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(LibraryBookmark::class, 'item_id');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LibraryProgress::class, 'item_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(MediaAsset::class)->latest();
    }
}
