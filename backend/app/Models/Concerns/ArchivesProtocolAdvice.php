<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/** Library advice a protocol refers to is archived, never deleted: protocols keep referring to it by id. */
trait ArchivesProtocolAdvice
{
    /** New measures join the end of their category unless a position is given. */
    protected static function bootArchivesProtocolAdvice(): void
    {
        static::creating(function ($measure): void {
            if (! $measure->sort_order) {
                $measure->sort_order = ((int) static::query()->max('sort_order')) + 1;
            }
        });
    }

    protected function initializeArchivesProtocolAdvice(): void
    {
        $this->mergeCasts(['archived_at' => 'datetime', 'sort_order' => 'integer']);
    }

    /** Library order as set by admins; title and id keep ties deterministic. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('title')->orderBy('id');
    }

    /**
     * Advice a protocol can choose from: everything current, plus what it already uses.
     *
     * @param  array<int, string>  $selectedIds
     */
    public function scopeAvailableFor(Builder $query, array $selectedIds = []): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('archived_at')
            ->when($selectedIds, fn ($q) => $q->orWhereIn('id', $selectedIds)));
    }

    /**
     * Validation counterpart of {@see scopeAvailableFor()}.
     *
     * @param  array<int, string>  $selectedIds
     */
    public static function selectionRule(array $selectedIds = []): Exists
    {
        return Rule::exists((new static)->getTable(), 'id')->where(fn ($q) => $q
            ->where(fn ($q) => $q->whereNull('archived_at')
                ->when($selectedIds, fn ($q) => $q->orWhereIn('id', $selectedIds))));
    }
}
