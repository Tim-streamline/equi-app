<?php

namespace Database\Seeders;

use App\Models\LibraryBookmark;
use App\Models\LibraryItem;
use App\Models\LibraryProgress;
use App\Models\User;
use Illuminate\Database\Seeder;

class LibraryEngagementSeeder extends Seeder
{
    public function run(): void
    {
        $items = LibraryItem::all();
        $users = User::all();

        foreach ($users as $user) {
            $bookmarks = $items->random(fake()->numberBetween(0, 6));
            foreach ($bookmarks as $item) {
                LibraryBookmark::firstOrCreate([
                    'user_id' => $user->id, 'item_id' => $item->id,
                ]);
            }

            $progressed = $items->random(fake()->numberBetween(0, 12));
            foreach ($progressed as $item) {
                $progress = fake()->randomFloat(2, 0, 1);
                LibraryProgress::firstOrCreate(
                    ['user_id' => $user->id, 'item_id' => $item->id],
                    [
                        'position_sec' => (int) (($item->duration_sec ?? 300) * $progress),
                        'progress' => $progress,
                        'completed' => $progress >= 0.95,
                        'last_viewed_at' => fake()->dateTimeBetween('-2 months', 'now'),
                    ],
                );
            }
        }
    }
}
