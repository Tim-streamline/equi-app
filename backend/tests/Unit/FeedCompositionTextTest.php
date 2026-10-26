<?php

namespace Tests\Unit;

use App\Support\FeedCompositionMatcher;
use PHPUnit\Framework\TestCase;

class FeedCompositionTextTest extends TestCase
{
    public function test_cosmetic_changes_match_but_amount_order_and_ingredient_changes_do_not(): void
    {
        $original = FeedCompositionMatcher::canonical('Timotheegras, luzerne, lijnzaad (7,88%), zout.');
        $this->assertSame($original, FeedCompositionMatcher::canonical("TIMOTHEEGRAS\nLuzerne; Lijnzaad 7.880% · zout"));
        foreach (['Timotheegras, luzerne, lijnzaad (8%), zout', 'Luzerne, timotheegras, lijnzaad (7,88%), zout', 'Timotheegras, luzerne, lijnzaad (7,88%), melasse', 'Timotheegras, luzerne, lijnzaad 7,88, zout'] as $changed) {
            $this->assertNotSame($original, FeedCompositionMatcher::canonical($changed));
        }
    }

    public function test_completeness_requires_the_composition_heading_and_the_next_section(): void
    {
        $this->assertSame('Timothee, lijnzaad, zout.', FeedCompositionMatcher::section("Merknaam\nSamenstelling:\nTimothee, lijnzaad, zout.\nAnalytische bestanddelen\nEiwit 10%"));
        $this->assertNull(FeedCompositionMatcher::section("Samenstelling:\nTimothee, lijnzaad, zout."));
        $this->assertNull(FeedCompositionMatcher::section("Timothee, lijnzaad, zout.\nAnalytische bestanddelen"));
    }

    public function test_visible_additives_are_not_stripped_from_the_recipe(): void
    {
        $section = FeedCompositionMatcher::section("Samenstelling:\nTimothee, zout.\nToevoegingen:\nNatriumseleniet.\nAnalyse\nEiwit 10%");
        $this->assertStringContainsString('Natriumseleniet', $section);
        $this->assertNotSame(FeedCompositionMatcher::canonical($section), FeedCompositionMatcher::canonical(str_replace('Natriumseleniet', 'Seleniumgist', $section)));
    }
}
