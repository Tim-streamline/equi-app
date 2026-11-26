<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DrinkingWaterArticleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TITLE = 'Drinkwater onder de loep: wat krijgt je paard binnen?';

    private function item(string $slug, array $values): string
    {
        // Raw inserts mirror production rows without the model's saving normalisation.
        DB::table('library_items')->insert($values + ['slug' => $slug, 'format' => 'article', 'title' => self::TITLE, 'credit_cost' => 0, 'is_plus' => false, 'equiboost_access' => null]);

        return $slug;
    }

    public function test_only_a_non_plus_credit_priced_matching_article_becomes_included(): void
    {
        $paid = $this->item('drinkwater-paid', ['credit_cost' => 3]);
        $plus = $this->item('drinkwater-plus', ['credit_cost' => 2, 'is_plus' => true]);
        $free = $this->item('drinkwater-free', ['credit_cost' => 0]);
        $only = $this->item('drinkwater-only', ['credit_cost' => 0, 'equiboost_access' => 'only']);
        $included = $this->item('drinkwater-included', ['credit_cost' => 4, 'equiboost_access' => 'included']);
        $other = $this->item('ander-artikel', ['credit_cost' => 3, 'title' => 'Hooi onder de loep']);
        $before = DB::table('library_items')->get()->keyBy('slug');

        $migration = require database_path('migrations/2026_11_18_000002_include_drinking_water_article_in_equiboost.php');
        $migration->up();
        $after = DB::table('library_items')->get()->keyBy('slug');

        $this->assertSame('included', $after[$paid]->equiboost_access);
        $this->assertSame(3, (int) $after[$paid]->credit_cost);
        foreach ([$plus, $free, $only, $included, $other] as $slug) {
            $this->assertEquals($before[$slug], $after[$slug], $slug.' keeps its existing access');
        }

        // Rerunning (e.g. on staging, which already has the included row) changes nothing.
        $migration->up();
        $this->assertEquals($after, DB::table('library_items')->get()->keyBy('slug'));
    }
}
