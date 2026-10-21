<?php

namespace App\Support;

use Illuminate\Support\Collection;

class LibraryHomeSeries
{
    /** Keep one purchasable entry per configured series, in sequence. */
    public function filter(Collection $unreadableItems): Collection
    {
        $series = $unreadableItems
            ->filter(fn ($item) => filled($item->series_name) && (int) $item->series_part > 0)
            ->groupBy(fn ($item) => mb_strtolower(trim($item->series_name)))
            ->map(fn ($group) => $group->sortBy([
                ['series_part', 'asc'], ['order', 'asc'], ['id', 'asc'],
            ])->first()->id);

        return $unreadableItems->filter(function ($item) use ($series): bool {
            if (filled($item->series_name) && (int) $item->series_part > 0) {
                return $series->get(mb_strtolower(trim($item->series_name))) === $item->id;
            }

            // Older content has only a title suffix. Avoid recommending a later
            // part until its series has been linked explicitly in the admin.
            if (preg_match('/(?:^|\s)([1-9][0-9]*)\s*\/\s*([1-9][0-9]*)$/u', trim($item->title), $part)) {
                return (int) $part[1] <= 1 || (int) $part[1] > (int) $part[2];
            }
            if (preg_match('/\bdeel\s+([1-9][0-9]*)$/iu', trim($item->title), $part)) {
                return (int) $part[1] === 1;
            }

            return true;
        });
    }
}
