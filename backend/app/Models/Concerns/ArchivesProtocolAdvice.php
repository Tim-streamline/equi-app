<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

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
}
