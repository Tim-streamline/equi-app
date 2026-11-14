<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/** Library advice is archived, never deleted: protocols keep referring to it by id. */
trait ArchivesProtocolAdvice
{
    protected function initializeArchivesProtocolAdvice(): void
    {
        $this->mergeCasts(['archived_at' => 'datetime']);
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
