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

    public function test_water_link_never_falls_back_to_another_item(): void
    {
        $protocol = new Protocol(['customer_settings' => ['water_library_item_id' => 'old']]);
        $old = new LibraryItem(['title' => 'Wateranalyse', 'slug' => 'wateranalyse', 'description' => 'Laat je water analyseren.']);
        $old->id = 'old';
        $links = app(HorseDashboard::class)->nutritionLinks($protocol, collect([$old]), fn ($item) => ['id' => $item->id]);
        $this->assertNull($links['waterLibraryItem']);
    }

    public function test_hay_link_prefers_the_configured_item_over_a_topic_match(): void
    {
        $protocol = new Protocol(['customer_settings' => ['hay_library_item_id' => 'chosen']]);
        $topic = new LibraryItem(['title' => 'Hooianalyse', 'slug' => 'hooianalyse']);
        $topic->id = 'topic';
        $chosen = new LibraryItem(['title' => 'Ruwvoer', 'slug' => 'ruwvoer']);
        $chosen->id = 'chosen';
        $dashboard = app(HorseDashboard::class);
        $this->assertSame(['id' => 'chosen'], $dashboard->nutritionLinks($protocol, collect([$topic, $chosen]), fn ($item) => ['id' => $item->id])['hayLibraryItem']);
        $this->assertSame(['id' => 'topic'], $dashboard->nutritionLinks(new Protocol, collect([$topic, $chosen]), fn ($item) => ['id' => $item->id])['hayLibraryItem']);
    }
}
