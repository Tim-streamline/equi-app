<?php

namespace Tests\Feature;

use App\Models\LibraryItem;
use App\Models\Protocol;
use App\Support\HorseDashboard;
use Tests\TestCase;

class ProtocolWaterArticleTest extends TestCase
{
    public function test_named_drinking_water_article_is_linked_even_without_analysis_in_its_title(): void
    {
        $protocol = new Protocol(['customer_settings' => ['water_library_item_id' => 'old']]);
        $old = new LibraryItem(['title' => 'Wateranalyse', 'slug' => 'wateranalyse']);
        $old->id = 'old';
        $article = new LibraryItem(['title' => 'Drinkwater onder de loep: wat krijgt je paard binnen?', 'slug' => 'drinkwater-onder-de-loep']);
        $article->id = 'water';
        $links = app(HorseDashboard::class)->nutritionLinks($protocol, collect([$old, $article]), fn ($item) => ['id' => $item->id]);
        $this->assertSame(['id' => 'water'], $links['waterLibraryItem']);
        $this->assertNull($links['hayLibraryItem']);
    }
}
