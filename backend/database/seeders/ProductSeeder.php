<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Support\AuditLogger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            ['Pavo', 'Pavo Care 4 Life - supplement', 'supplement'],
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
            DB::transaction(function () use ($brand, $name, $category): void {
                $product = Product::create([
                    'brand' => $brand,
                    'name' => $name,
                    'barcode' => fake()->unique()->ean13(),
                    'category' => $category,
                ]);
                AuditLogger::log('demo_seeded', $product, after: $product->only('brand', 'name', 'barcode', 'category'),
                    reason: 'ProductSeeder: voorbeeldproduct met een verzonnen barcode.');
            });
        }
    }
}
