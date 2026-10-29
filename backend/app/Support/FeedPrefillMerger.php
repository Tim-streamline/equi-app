<?php

namespace App\Support;

/** Combine independent label readings without choosing between conflicting facts. */
class FeedPrefillMerger
{
    public static function merge(array $readings): array
    {
        $raw = $readings[0];
        $notes = [];
        $nutrientConflicts = [];
        foreach (array_slice($readings, 1) as $next) {
            foreach (['brand', 'name', 'barcode'] as $field) {
                if (filled($next[$field]) && $next[$field] !== 'unknown') {
                    $raw[$field] = $next[$field];
                }
            }
            foreach (['category' => 'category_confidence', 'composition_text' => 'composition_confidence'] as $field => $confidence) {
                if (filled($next[$field]) && $next[$field] !== 'unknown' && ($next[$confidence] === 'high' || $raw[$confidence] !== 'high')) {
                    $raw[$field] = $next[$field];
                    $raw[$confidence] = $next[$confidence];
                }
            }
        }
        $raw['unreadable_fields'] = collect($readings)->pluck('unreadable_fields')->flatten(1)->unique()->values()->all();
        $raw['ingredients'] = [];
        foreach (collect($readings)->pluck('ingredients')->flatten(1)->groupBy(fn ($row) => FeedCatalog::normalize($row['label'])) as $rows) {
            $row = $rows->first();
            $row['confidence'] = $rows->contains('confidence', 'high') ? 'high' : 'low';
            $row['evidence'] = self::sources($rows->pluck('evidence')->all());
            foreach (['amount' => null, 'added_selenium' => 'unknown', 'selenium_form' => 'unknown'] as $field => $unknown) {
                $contributors = $rows->filter(fn ($reading) => filled($reading[$field]) && $reading[$field] !== 'unknown');
                $values = $contributors->pluck($field)
                    ->unique(fn ($value) => FeedCatalog::normalize((string) $value))->values();
                $row[$field] = $values->count() === 1 ? $values->first() : $unknown;
                // A clear name cannot certify a value supplied only by uncertain readings.
                if ($values->count() === 1 && ! $contributors->contains('confidence', 'high')) {
                    $row['confidence'] = 'low';
                }
                if ($values->count() > 1) {
                    $row['confidence'] = 'low';
                    $notes[] = "{$row['label']}: tegenstrijdige etiketgegevens (".$values->implode(' / ').'). Controleer de foto’s en vul dit zelf in.';
                }
            }
            $raw['ingredients'][] = $row;
        }
        $raw['nutrients'] = [];
        foreach (collect($readings)->pluck('nutrients')->flatten(1)->groupBy(fn ($row) => $row['code'].'|'.$row['basis']) as $rows) {
            // An unreadable number cannot contradict a legible number in another crop.
            $values = $rows->filter(fn ($row) => is_numeric($row['value']));
            $distinct = $values->unique(fn ($row) => json_encode([(float) $row['value'], $row['unit'], $row['qualifier']]));
            if ($distinct->count() > 1) {
                $first = $rows->first();
                $nutrientConflicts[] = ['code' => $first['code'], 'basis' => $first['basis']];
                $label = FeedCatalog::NUTRIENTS[$first['code']];
                $basis = ['product' => 'productbasis', 'dry_matter' => 'droge stof', 'unknown' => 'basis onbekend'][$first['basis']];
                $notes[] = "$label ($basis): tegenstrijdige waarden (".$distinct->map(fn ($row) => $row['qualifier'].' '.$row['value'].' '.$row['unit'])->implode(' / ').'). Niet ingevuld; controleer de foto’s en voeg de juiste waarde toe.';

                continue;
            }
            $row = $values->first() ?? $rows->first();
            $row['source'] = self::sources($rows->pluck('source')->all());
            $row['confidence'] = $values->contains('confidence', 'high') ? 'high' : 'low';
            $raw['nutrients'][] = $row;
        }

        return ['extraction' => $raw, 'notes' => array_values(array_unique($notes)), 'nutrient_conflicts' => $nutrientConflicts];
    }

    private static function sources(array $sources): string
    {
        return mb_substr(collect($sources)->filter()->unique()->implode("\n"), 0, 2000);
    }
}
