<?php

namespace App\Support;

use Brick\Math\BigDecimal;

/** Only complete, explicit label facts may coalesce duplicate pending products. */
class FeedRecipeIdentity
{
    public static function fingerprint(array $raw): ?string
    {
        if (! filled($raw['brand'] ?? null) || ! filled($raw['name'] ?? null) ||
            ($raw['composition_confidence'] ?? null) !== 'high' || ! filled($raw['composition_text'] ?? null) ||
            ($raw['category_confidence'] ?? null) !== 'high' || ($raw['category'] ?? 'unknown') === 'unknown' ||
            ($raw['unreadable_fields'] ?? []) !== [] || empty($raw['nutrients'])) {
            return null;
        }
        foreach (array_merge($raw['ingredients'], $raw['nutrients']) as $row) {
            if (($row['confidence'] ?? null) !== 'high') {
                return null;
            }
        }
        $nutrients = [];
        foreach ($raw['nutrients'] as $row) {
            if ($row['value'] === null || $row['basis'] === 'unknown' || ! filled($row['source'])) {
                return null;
            }
            $nutrients[] = [$row['code'], (string) BigDecimal::of($row['value'])->stripTrailingZeros(), $row['unit'], $row['basis'], $row['qualifier']];
        }
        sort($nutrients);
        $ingredients = array_map(fn ($row) => [FeedCompositionMatcher::canonical($row['label']),
            FeedCompositionMatcher::canonical($row['amount'] ?? ''), $row['added_selenium'], $row['selenium_form']], $raw['ingredients']);
        $brand = FeedCompositionMatcher::canonical($raw['brand']);
        $name = FeedCompositionMatcher::canonical($raw['name']);
        if (str_starts_with($name, $brand.' ')) {
            $name = substr($name, strlen($brand) + 1);
        }

        return hash('sha256', json_encode([$brand, $name, $raw['barcode'] ?? null, $raw['category'],
            FeedCompositionMatcher::canonical($raw['composition_text']), $ingredients, $nutrients], JSON_THROW_ON_ERROR));
    }
}
