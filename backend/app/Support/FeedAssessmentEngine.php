<?php

namespace App\Support;

use Brick\Math\BigRational as Decimal;
use Brick\Math\Exception\MathException;

/** Applies published policy limits only; no inferred ingredient weights or AI. */
class FeedAssessmentEngine
{
    public const VERSION = 'caps-1';

    public function evaluate(array $product, array $policy): array
    {
        $rows = $product['nutrients'] ?? [];
        $findings = [];
        $missing = [];
        $trace = [];
        $caps = [];
        $dm = $this->dryMatter($rows);
        $sugar = $this->nutrient('sugar', $rows, $dm, $trace);
        $starch = $this->nutrient('starch', $rows, $dm, $trace);
        foreach (['sugar' => $sugar, 'starch' => $starch] as $code => $interval) {
            if ($interval === null) {
                $missing[] = $code === 'sugar'
                    ? 'Suiker ontbreekt, is tegenstrijdig of kan niet betrouwbaar naar productbasis worden omgerekend.'
                    : 'Zetmeel ontbreekt, is tegenstrijdig of kan niet betrouwbaar naar productbasis worden omgerekend.';
            }
        }
        $sum = null;
        if ($sugar !== null && $starch !== null) {
            $maximum = $dm ?? Decimal::of(100);
            $sum = ['lower' => $sugar['lower']->plus($starch['lower']), 'upper' => $sugar['upper']->plus($starch['upper']),
                'lower_open' => $sugar['lower_open'] || $starch['lower_open'], 'upper_open' => $sugar['upper_open'] || $starch['upper_open']];
            if ($sum['lower']->isGreaterThan($maximum) || ($sum['lower']->isEqualTo($maximum) && $sum['lower_open'])) {
                $missing[] = $dm === null
                    ? 'Suiker + zetmeel is meer dan 100%; controleer de analysegegevens.'
                    : 'Suiker + zetmeel is meer dan de beschikbare droge stof ('.$this->text($dm).'% op productbasis); controleer de analysegegevens.';
                $sum = null;
            } else {
                if ($sum['upper']->isGreaterThan($maximum)) {
                    $sum['upper'] = $maximum;
                    $sum['upper_open'] = false;
                }
                foreach ($policy['sugar_starch_caps'] as $tier) {
                    $comparison = $sum['lower']->compareTo((string) $tier['threshold']);
                    $applies = $comparison > 0 || ($comparison === 0 && ($tier['inclusive'] || $sum['lower_open']));
                    if ($applies) {
                        $caps[] = ['code' => 'sugar_starch', 'max_score' => (string) $tier['max_score'],
                            'message' => 'Suiker + zetmeel '.($tier['inclusive'] ? 'vanaf ' : 'boven ').$tier['threshold'].'%: maximaal '.$tier['max_score'].'/10.'];
                    }
                }
                $findings[] = ['code' => 'sugar_starch', 'message' => $this->isExact($sum)
                    ? 'Suiker + zetmeel samen: '.$this->text($sum['lower']).'% op productbasis.'
                    : 'Suiker + zetmeel is een begrensde waarde. Alleen zeker geldende plafonds worden toegepast.'];
                if (! $this->isExact($sum)) {
                    $missing[] = 'Exacte suiker- en zetmeelwaarden ontbreken; er zijn alleen grenswaarden beschikbaar.';
                }
            }
        }
        if (empty($product['ingredients']) && trim($product['composition_text'] ?? '') === '') {
            $missing[] = 'De samenstelling ontbreekt.';
        }
        foreach ($product['ingredients'] ?? [] as $row) {
            $form = $row['selenium_form'] ?? null;
            $added = $row['added_selenium'] ?? 'unknown';
            $evidence = trim($row['evidence'] ?? '');
            if ($evidence === '') {
                if ($added === 'confirmed' || in_array($form, ['organic', 'inorganic'], true)) {
                    $missing[] = 'Een bronvermelding voor de opgegeven seleniumtoevoeging of seleniumvorm ontbreekt.';
                }

                continue;
            }
            if ($form === 'organic') {
                $caps[] = ['code' => 'organic_selenium', 'max_score' => (string) $policy['organic_selenium_cap'],
                    'message' => 'Bevestigd organisch gebonden selenium: maximaal '.$policy['organic_selenium_cap'].'/10.', 'evidence' => $evidence];
            } elseif ($form === 'inorganic') {
                $findings[] = ['code' => 'inorganic_selenium', 'message' => 'Bevestigd anorganisch selenium: geen seleniumgerelateerd plafond.', 'evidence' => $evidence];
            } elseif ($added === 'confirmed') {
                $caps[] = ['code' => 'unknown_added_selenium', 'max_score' => (string) $policy['unknown_added_selenium_cap'],
                    'message' => 'Bevestigd toegevoegd selenium, vorm onbekend: maximaal '.$policy['unknown_added_selenium_cap'].'/10.', 'evidence' => $evidence];
            }
        }
        if (in_array('selenium_total', array_column($rows, 'code'), true)) {
            $findings[] = ['code' => 'selenium_total', 'message' => 'Selenium totaal bewijst geen toevoeging of chemische vorm en activeert zelf geen plafond.'];
        }
        usort($caps, fn ($a, $b) => Decimal::of($a['max_score'])->compareTo($b['max_score']) ?: strcmp($a['message'], $b['message']));
        $cap = $caps === [] ? null : (string) $caps[0]['max_score'];
        $zero = $cap !== null && Decimal::of($cap)->isZero();

        return ['status' => $zero ? 'zero_by_cap' : ($missing === [] ? 'awaiting_calibration' : 'insufficient_data'),
            'automatic_score' => $zero ? '0' : null, 'score_cap' => $cap,
            'sugar_starch' => $sum === null ? null : $this->serialize($sum),
            'normalization' => $trace, 'applied_caps' => $caps, 'findings' => $findings, 'missing_data' => array_values(array_unique($missing)),
            'calibration_pending' => true];
    }

    private function dryMatter(array $rows): ?Decimal
    {
        $values = [];
        foreach ($rows as $row) {
            if (! in_array($row['code'] ?? null, ['dry_matter', 'moisture'], true) || ($row['basis'] ?? null) !== 'product' || ($row['qualifier'] ?? 'exact') !== 'exact') {
                continue;
            }
            $value = $this->percent($row);
            if ($value === null || $value->isNegative() || $value->isGreaterThan(100)) {
                return null;
            }
            $values[] = $row['code'] === 'moisture' ? Decimal::of(100)->minus($value) : $value;
        }
        if ($values === [] || ! $values[0]->isGreaterThan(0)) {
            return null;
        }
        foreach ($values as $value) {
            if (! $value->isEqualTo($values[0])) {
                return null;
            }
        }

        return $values[0];
    }

    private function percent(array $row): ?Decimal
    {
        $raw = $row['value'] ?? null;
        if (! is_numeric($raw) || strlen((string) $raw) > 80) {
            return null;
        }
        try {
            $value = Decimal::of((string) $raw);

            return match ($row['unit'] ?? null) {
                '%' => $value, 'g/kg' => $value->dividedBy(10), 'mg/kg' => $value->dividedBy(10000), default => null,
            };
        } catch (MathException) {
            return null;
        }
    }

    private function nutrient(string $code, array $rows, ?Decimal $dm, array &$trace): ?array
    {
        $result = null;
        foreach ($rows as $row) {
            if (($row['code'] ?? null) !== $code) {
                continue;
            }
            $basis = $row['basis'] ?? 'unknown';
            if ($basis !== 'product' && ($basis !== 'dry_matter' || $dm === null)) {
                continue;
            }
            $value = $this->percent($row);
            if ($value === null || $value->isNegative() || $value->isGreaterThan(100)) {
                return null;
            }
            $qualifier = $row['qualifier'] ?? 'exact';
            $interval = match ($qualifier) {
                'exact' => ['lower' => $value, 'upper' => $value, 'lower_open' => false, 'upper_open' => false],
                'minimum', 'greater_than' => ['lower' => $value, 'upper' => Decimal::of(100), 'lower_open' => $qualifier === 'greater_than', 'upper_open' => false],
                'maximum', 'less_than' => ['lower' => Decimal::of(0), 'upper' => $value, 'lower_open' => false, 'upper_open' => $qualifier === 'less_than'],
                default => null,
            };
            if ($interval === null) {
                return null;
            }
            if ($basis === 'dry_matter') {
                $interval['lower'] = $interval['lower']->multipliedBy($dm)->dividedBy(100);
                $interval['upper'] = $interval['upper']->multipliedBy($dm)->dividedBy(100);
            }
            $trace[] = ['code' => $code, 'original' => $row, 'dry_matter_percent' => $basis === 'dry_matter' ? $this->text($dm) : null,
                'product_basis' => $this->serialize($interval)];
            if ($result === null) {
                $result = $interval;
            } else {
                $low = $result['lower']->compareTo($interval['lower']);
                $high = $result['upper']->compareTo($interval['upper']);
                if ($low < 0) {
                    $result['lower'] = $interval['lower'];
                    $result['lower_open'] = $interval['lower_open'];
                } elseif ($low === 0) {
                    $result['lower_open'] = $result['lower_open'] || $interval['lower_open'];
                }
                if ($high > 0) {
                    $result['upper'] = $interval['upper'];
                    $result['upper_open'] = $interval['upper_open'];
                } elseif ($high === 0) {
                    $result['upper_open'] = $result['upper_open'] || $interval['upper_open'];
                }
            }
            $comparison = $result['lower']->compareTo($result['upper']);
            if ($comparison > 0 || ($comparison === 0 && ($result['lower_open'] || $result['upper_open']))) {
                return null;
            }
        }

        return $result;
    }

    private function isExact(array $value): bool
    {
        return $value['lower']->isEqualTo($value['upper']) && ! $value['lower_open'] && ! $value['upper_open'];
    }

    private function text(Decimal $value): string
    {
        return (string) $value->toBigDecimal();
    }

    private function serialize(array $value): array
    {
        return ['lower' => $this->text($value['lower']), 'upper' => $this->text($value['upper']),
            'lower_open' => $value['lower_open'], 'upper_open' => $value['upper_open'], 'exact' => $this->isExact($value)];
    }
}
