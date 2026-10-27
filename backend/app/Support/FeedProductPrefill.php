<?php

namespace App\Support;

use App\Models\Ingredient;
use App\Models\IngredientRevision;

/**
 * Turns what the label reader found into a draft for the admin product form.
 * Nothing is saved: the administrator checks every value and decides what to keep.
 */
class FeedProductPrefill
{
    /**
     * @return array{data: array<string, mixed>, notes: list<string>}
     */
    public function fromExtraction(array $raw): array
    {
        $notes = [];
        $category = isset(FeedCatalog::CATEGORIES[$raw['category'] ?? '']) ? $raw['category'] : '';
        if (! $category) {
            $notes[] = 'Categorie niet herkend: kies zelf de categorie.';
        } elseif (($raw['category_confidence'] ?? 'low') !== 'high') {
            $notes[] = 'Categorie is een inschatting: controleer de categorie.';
        }
        if (($raw['composition_confidence'] ?? 'low') !== 'high' && filled($raw['composition_text'] ?? null)) {
            $notes[] = 'De samenstellingstekst was niet volledig leesbaar: vergelijk hem met het etiket.';
        }

        $cards = $this->cards();
        $ingredients = [];
        foreach ($raw['ingredients'] ?? [] as $index => $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $match = $cards[FeedCatalog::normalize($label)] ?? null;
            $number = count($ingredients) + 1;
            if (($row['confidence'] ?? 'low') !== 'high') {
                $notes[] = "Ingrediënt {$number} ({$label}): niet zeker gelezen.";
            }
            if (! $match) {
                $notes[] = "Ingrediënt {$number} ({$label}): geen kenniskaart gevonden, koppel zelf.";
            }
            $ingredients[] = [
                'label' => $label, 'amount' => $row['amount'] ?? '', 'ingredient_id' => $match ?? '',
                // A name match is a proposal; the administrator confirms it.
                'match_status' => $match ? 'suggested' : 'unknown',
                'added_selenium' => $row['added_selenium'] ?? 'unknown',
                'selenium_form' => ($row['selenium_form'] ?? 'unknown') === 'unknown' ? '' : $row['selenium_form'],
                'evidence' => $row['evidence'] ?? '',
            ];
        }

        $nutrients = [];
        foreach ($raw['nutrients'] ?? [] as $row) {
            $value = str_replace(',', '.', (string) ($row['value'] ?? ''));
            if (! is_numeric($value) || ! isset(FeedCatalog::NUTRIENTS[$row['code'] ?? ''])) {
                continue;
            }
            $label = FeedCatalog::NUTRIENTS[$row['code']];
            if (($row['confidence'] ?? 'low') !== 'high') {
                $notes[] = "{$label}: waarde niet zeker gelezen.";
            }
            if (($row['basis'] ?? 'unknown') === 'unknown') {
                $notes[] = "{$label}: analysebasis niet vermeld, controleer of het per kg product of op droge stof is.";
            }
            $nutrients[] = ['code' => $row['code'], 'value' => $value, 'qualifier' => $row['qualifier'] ?? 'exact',
                'unit' => $row['unit'] ?? '%', 'basis' => $row['basis'] ?? 'unknown', 'source' => $row['source'] ?? ''];
        }
        foreach ($raw['unreadable_fields'] ?? [] as $field) {
            $notes[] = "Niet leesbaar: {$field}.";
        }

        return ['data' => array_filter([
            'brand' => $raw['brand'] ?? null, 'name' => $raw['name'] ?? null, 'barcode' => $raw['barcode'] ?? null,
            'category' => $category ?: null, 'composition_text' => $raw['composition_text'] ?? null,
        ], fn ($value) => filled($value)) + ['ingredients' => $ingredients, 'nutrients' => $nutrients], 'notes' => array_values(array_unique($notes))];
    }

    /** Knowledge cards by their name and published exact terms; an ambiguous term proposes nothing. */
    private function cards(): array
    {
        $ingredients = Ingredient::where('catalog_status', 'published')->whereNotNull('published_version_id')->get();
        $revisions = IngredientRevision::whereIn('id', $ingredients->pluck('published_version_id'))->get()->keyBy('id');
        $terms = [];
        foreach ($ingredients as $ingredient) {
            $revision = $revisions[$ingredient->published_version_id] ?? null;
            if (! $revision || $revision->status !== 'published') {
                continue;
            }
            $names = [$revision->data['name'] ?? ''];
            foreach ($revision->data['terms'] ?? [] as $term) {
                if (($term['kind'] ?? '') === 'exact') {
                    $names[] = $term['term'];
                }
            }
            foreach ($names as $name) {
                $key = FeedCatalog::normalize((string) $name);
                $terms[$key] = isset($terms[$key]) && $terms[$key] !== $ingredient->id ? false : $ingredient->id;
            }
        }

        return array_filter($terms);
    }
}
