<?php

namespace Database\Seeders;

use App\Models\Horse;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ScanIngredient;
use App\Models\ScanResult;
use App\Models\User;
use Illuminate\Database\Seeder;

class ScanSeeder extends Seeder
{
    public function run(): void
    {
        $products = Product::all();
        $ingredients = Ingredient::all();
        $months = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

        foreach (User::all() as $user) {
            $horse = Horse::where('owner_id', $user->id)->where('status', 'active')->first();
            $count = fake()->numberBetween(2, 10);
            for ($i = 0; $i < $count; $i++) {
                $product = $products->random();
                $score = fake()->numberBetween(20, 99);
                $rating = $score >= 75 ? 'Goed' : ($score >= 50 ? 'Matig' : 'Slecht');
                $scannedAt = fake()->dateTimeBetween('-4 months', 'now');
                $scan = ScanResult::create([
                    'user_id' => $user->id,
                    'horse_id' => $horse?->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'brand' => $product->brand,
                    'scanned_at' => $scannedAt,
                    'when_label' => $scannedAt->format('j') . ' ' . $months[(int) $scannedAt->format('n') - 1],
                    'score' => $score,
                    'rating' => $rating,
                    'advice' => fake()->boolean(60) ? fake()->paragraph(2) : null,
                    'bookmarked' => fake()->boolean(15),
                ]);

                $pick = $ingredients->random(fake()->numberBetween(3, 7));
                foreach ($pick as $j => $ing) {
                    ScanIngredient::create([
                        'scan_id' => $scan->id,
                        'ingredient_id' => $ing->id,
                        'name' => $ing->name,
                        'tag' => fake()->randomElement([
                            $ing->default_tag, $ing->default_tag, $ing->default_tag,
                            fake()->randomElement(['good', 'warn', 'danger']),
                        ]),
                        'description' => $ing->description,
                        'order' => $j,
                    ]);
                }
            }
        }
    }
}
