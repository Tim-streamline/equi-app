<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            ['Pavo', 'Pavo Care 4 Life — supplement', 'supplement'],
            ['Pavo', 'Pavo SpeediBeet', 'feed'],
            ['Pavo', 'Pavo Slobber Mash', 'feed'],
            ['Pavo', 'Pavo Vital', 'supplement'],
            ['Equilin', 'Equilin Magnesium', 'supplement'],
            ['Equilin', 'Equilin Brandnetel Pluksel', 'herb'],
            ['Cavalor', 'Cavalor Free Breath', 'supplement'],
            ['Cavalor', 'Cavalor Strucomash', 'feed'],
            ['Cavalor', 'Cavalor FiberForce', 'feed'],
            ['Hartog', 'Hartog Compleet Senior', 'feed'],
            ['Hartog', 'Hartog Easy Mix', 'feed'],
            ['Sectolin', 'Sectolin Mariadistel', 'herb'],
            ['Sectolin', 'Sectolin Spirulina', 'supplement'],
            ['Hippo', 'Hippo Mineral Mix', 'supplement'],
            ['Hippo', 'Hippo Lijnzaad Geplet', 'herb'],
            ['Reverdy', 'Reverdy Pro-Senior', 'feed'],
            ['Royal Horse', 'Royal Horse A50', 'feed'],
            ['Twydil', 'Twydil Stomacare', 'supplement'],
            ['Equifirst', 'Equifirst Hooicobs', 'feed'],
            ['Cavalor', 'Cavalor Vitaflora', 'supplement'],
            ['Sectolin', 'Sectolin Probiotica', 'supplement'],
            ['Equilin', 'Equilin Hoefsupport', 'supplement'],
        ];
        foreach ($catalog as [$brand, $name, $category]) {
            Product::create([
                'brand' => $brand,
                'name' => $name,
                'barcode' => fake()->unique()->ean13(),
                'category' => $category,
            ]);
        }
    }
}
