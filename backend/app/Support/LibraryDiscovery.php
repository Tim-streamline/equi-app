<?php

namespace App\Support;

use App\Models\LibraryItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LibraryDiscovery
{
    public function related(LibraryItem $item): Collection
    {
        $item->loadMissing('categories');
        $categories = $item->categories->modelKeys();
        $words = $this->words($item->title.' '.$item->description);
        $pins = array_flip($item->featured_suggestion_ids ?? []);

        return LibraryItem::query()->where('id', '!=', $item->id)
            ->whereNotNull('published_at')->where('published_at', '<=', now())->with('categories')->get()
            ->sort(function ($a, $b) use ($categories, $words, $pins) {
                $rank = fn ($candidate) => [
                    isset($pins[$candidate->id]) ? 0 : 1,
                    $pins[$candidate->id] ?? 4,
                    -count(array_intersect($categories, $candidate->categories->modelKeys())),
                    -count(array_intersect($words, $this->words($candidate->title.' '.$candidate->description))),
                    $candidate->title,
                    $candidate->id,
                ];

                return $rank($a) <=> $rank($b);
            })->take(4)->values();
    }

    public function access(User $user): array
    {
        return [
            'hasPlus' => $user->subscriptions()->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('started_at')->orWhereDate('started_at', '<=', today()))
                ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhereDate('cancelled_at', '>', today()))
                ->whereHas('plan', fn ($q) => $q->where('slug', 'plus'))->exists(),
            'credits' => (int) (DB::table('library_credit_balances')->where('user_id', $user->id)->value('balance') ?? 0),
            'unlockedIds' => DB::table('library_unlocks')->where('user_id', $user->id)->pluck('item_id')->all(),
        ];
    }

    public function canRead(LibraryItem $item, array $access): bool
    {
        return $access['hasPlus'] || in_array($item->id, $access['unlockedIds'], true)
            || (! $item->is_plus && (int) $item->credit_cost === 0);
    }

    public function summary(LibraryItem $item): array
    {
        return [
            'id' => $item->id, 'title' => $item->title, 'description' => $item->description,
            'format' => $item->format, 'heroImageUrl' => $item->hero_image_url,
            'durationLabel' => $item->duration_label, 'creditCost' => (int) $item->credit_cost,
            'isPlus' => $item->is_plus,
        ];
    }

    private function words(string $text): array
    {
        $stop = ['deze', 'voor', 'door', 'over', 'wordt', 'zijn', 'naar', 'heeft', 'jouw', 'meer', 'with', 'that'];

        return array_values(array_diff(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(strip_tags($text))),
            fn ($word) => mb_strlen($word) >= 4,
        )), $stop));
    }
}
