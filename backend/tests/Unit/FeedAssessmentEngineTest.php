<?php

namespace Tests\Unit;

use App\Support\FeedAssessmentEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FeedAssessmentEngineTest extends TestCase
{
    private function policy(): array
    {
        return ['sugar_starch_caps' => [
            ['threshold' => 20, 'inclusive' => true, 'max_score' => 0],
            ['threshold' => 15, 'inclusive' => false, 'max_score' => 4],
            ['threshold' => 12, 'inclusive' => false, 'max_score' => 5],
        ], 'organic_selenium_cap' => 3, 'unknown_added_selenium_cap' => 5.5];
    }

    private function row(string $code, string $value, string $basis = 'product', string $unit = '%', string $qualifier = 'exact'): array
    {
        return compact('code', 'value', 'basis', 'unit', 'qualifier');
    }

    private function assess(array $nutrients, array $ingredients = [], ?array $policy = null): array
    {
        return (new FeedAssessmentEngine)->evaluate(['composition_text' => 'Samenstelling volgens etiket', 'nutrients' => $nutrients, 'ingredients' => $ingredients], $policy ?? $this->policy());
    }

    public static function boundaries(): array
    {
        return [
            'zero is known, not missing' => ['0', '0', null],
            'exactly 12' => ['4', '8', null],
            'decimal addition exactly 12' => ['0.1', '11.9', null],
            'just over 12' => ['4', '8.000001', '5'],
            'exactly 15' => ['4', '11', '5'],
            'just over 15' => ['4', '11.000001', '4'],
            'just under 20' => ['4', '15.999999', '4'],
            'exactly 20' => ['4', '16', '0'],
            'over 20' => ['4', '16.000001', '0'],
            'Vente' => ['6.6', '26.4', '0'],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_exact_combined_thresholds_without_rounding(string $sugar, string $starch, ?string $cap): void
    {
        $result = $this->assess([$this->row('sugar', $sugar), $this->row('starch', $starch)]);
        $this->assertSame($cap, $result['score_cap']);
        $this->assertSame($cap === '0' ? '0' : null, $result['automatic_score']);
        $this->assertSame([], $result['missing_data']);
    }

    public function test_reference_scores_are_not_invented_from_two_low_sugar_products(): void
    {
        foreach ([['4.8', '1.86', '6.66'], ['3.1', '3.7', '6.8']] as [$sugar, $starch, $expected]) {
            $result = $this->assess([$this->row('sugar', $sugar), $this->row('starch', $starch)]);
            $this->assertSame($expected, $result['sugar_starch']['lower']);
            $this->assertNull($result['automatic_score']);
            $this->assertNull($result['score_cap']);
            $this->assertSame('awaiting_calibration', $result['status']);
        }
    }

    public function test_unknown_basis_and_missing_values_are_never_assumed(): void
    {
        foreach ([[$this->row('sugar', '33', 'unknown'), $this->row('starch', '1')], [$this->row('sugar', '33')], []] as $rows) {
            $result = $this->assess($rows);
            $this->assertNull($result['automatic_score']);
            $this->assertNull($result['sugar_starch']);
            $this->assertNotEmpty($result['missing_data']);
        }
    }

    public function test_dry_matter_and_units_are_converted_before_combining(): void
    {
        $dry = [$this->row('sugar', '10', 'dry_matter'), $this->row('starch', '10', 'dry_matter')];
        $result = $this->assess([...$dry, $this->row('moisture', '20')]);
        $this->assertSame('16', $result['sugar_starch']['lower']);
        $this->assertSame('4', $result['score_cap']);
        $this->assertSame('80', $result['normalization'][0]['dry_matter_percent']);
        $this->assertNull($this->assess($dry)['sugar_starch']);
        $this->assertNull($this->assess([...$dry, $this->row('moisture', '20'), $this->row('dry_matter', '90')])['sugar_starch']);
        $units = $this->assess([$this->row('sugar', '31', 'product', 'g/kg'), $this->row('starch', '37000', 'product', 'mg/kg')]);
        $this->assertSame('6.8', $units['sugar_starch']['lower']);
    }

    public function test_conflicting_or_physically_invalid_values_do_not_produce_scores(): void
    {
        $conflict = $this->assess([$this->row('sugar', '7'), $this->row('sugar', '10', 'dry_matter'), $this->row('moisture', '20'), $this->row('starch', '20')]);
        $this->assertNull($conflict['automatic_score']);
        $this->assertNull($conflict['sugar_starch']);
        $this->assertNull($this->assess([$this->row('sugar', '80'), $this->row('starch', '30')])['automatic_score']);
        $consistent = $this->assess([$this->row('sugar', '8'), $this->row('sugar', '10', 'dry_matter'), $this->row('moisture', '20'), $this->row('starch', '13')]);
        $this->assertSame('0', $consistent['automatic_score']);
    }

    public function test_inequalities_apply_only_proven_caps_and_preserve_open_boundaries(): void
    {
        $uncertain = $this->assess([$this->row('sugar', '10', qualifier: 'maximum'), $this->row('starch', '10', qualifier: 'maximum')]);
        $this->assertNull($uncertain['score_cap']);
        $this->assertNotEmpty($uncertain['missing_data']);
        $minimum = $this->assess([$this->row('sugar', '10', qualifier: 'minimum'), $this->row('starch', '10', qualifier: 'minimum')]);
        $this->assertSame('0', $minimum['automatic_score']);
        $strict = $this->assess([$this->row('sugar', '5', qualifier: 'greater_than'), $this->row('starch', '10', qualifier: 'minimum')]);
        $this->assertSame('4', $strict['score_cap']);
        $closed = $this->assess([$this->row('sugar', '5', qualifier: 'minimum'), $this->row('starch', '10', qualifier: 'minimum')]);
        $this->assertSame('5', $closed['score_cap']);
    }

    public static function impossibleDryMatterTotals(): array
    {
        return [
            'dry matter basis with moisture' => ['60', '50', 'dry_matter', 'dry_matter', 'moisture', '20', 'exact'],
            'dry matter basis with explicit dry matter' => ['60', '50', 'dry_matter', 'dry_matter', 'dry_matter', '80', 'exact'],
            'product basis' => ['48', '40', 'product', 'product', 'moisture', '20', 'exact'],
            'mixed bases' => ['60', '40', 'dry_matter', 'product', 'moisture', '20', 'exact'],
            'minimum exceeds available dry matter' => ['60', '50', 'dry_matter', 'dry_matter', 'moisture', '20', 'minimum'],
            'open lower boundary at all dry matter' => ['60', '40', 'dry_matter', 'dry_matter', 'moisture', '20', 'greater_than'],
        ];
    }

    #[DataProvider('impossibleDryMatterTotals')]
    public function test_combined_nutrients_cannot_exceed_known_dry_matter(string $sugar, string $starch, string $sugarBasis, string $starchBasis, string $dryMatterCode, string $dryMatterValue, string $qualifier): void
    {
        $result = $this->assess([
            $this->row('sugar', $sugar, $sugarBasis, qualifier: $qualifier),
            $this->row('starch', $starch, $starchBasis),
            $this->row($dryMatterCode, $dryMatterValue),
        ]);

        $this->assertSame('insufficient_data', $result['status']);
        $this->assertNull($result['sugar_starch']);
        $this->assertNull($result['score_cap']);
        $this->assertNull($result['automatic_score']);
        $this->assertSame([], $result['applied_caps']);
        $this->assertNotEmpty($result['missing_data']);
    }

    public function test_combined_nutrients_can_equal_all_available_dry_matter(): void
    {
        foreach (['exact', 'minimum'] as $qualifier) {
            $result = $this->assess([
                $this->row('sugar', '60', 'dry_matter', qualifier: $qualifier),
                $this->row('starch', '40', 'dry_matter'),
                $this->row('moisture', '20'),
            ]);

            $this->assertSame(['lower' => '80', 'upper' => '80', 'lower_open' => false, 'upper_open' => false, 'exact' => true], $result['sugar_starch']);
            $this->assertSame('0', $result['automatic_score']);
            $this->assertSame([], $result['missing_data']);
        }
    }

    public static function invalidDryMatterValues(): array
    {
        return [
            'moisture contradicts dry matter' => [[['moisture', '90'], ['dry_matter', '20']]],
            'dry matter contradicts moisture' => [[['dry_matter', '20'], ['moisture', '90']]],
            'conflicting dry matter rows' => [[['dry_matter', '10'], ['dry_matter', '20']]],
            'conflicting moisture rows' => [[['moisture', '90'], ['moisture', '80']]],
            'zero contradicts positive dry matter' => [[['dry_matter', '0'], ['dry_matter', '20']]],
            'negative dry matter' => [[['dry_matter', '-1']]],
            'moisture exceeds 100 percent' => [[['moisture', '101']]],
        ];
    }

    #[DataProvider('invalidDryMatterValues')]
    public function test_invalid_dry_matter_prevents_product_basis_sugar_starch_caps(array $values): void
    {
        $rows = array_map(fn ($row) => $this->row(...$row), $values);
        $result = $this->assess([$this->row('sugar', '10'), $this->row('starch', '20'), ...$rows]);

        $this->assertSame('insufficient_data', $result['status']);
        $this->assertNull($result['sugar_starch']);
        $this->assertNull($result['score_cap']);
        $this->assertNull($result['automatic_score']);
        $this->assertSame([], $result['applied_caps']);
        $this->assertNotEmpty($result['missing_data']);
    }

    public static function zeroDryMatterValues(): array
    {
        return [
            'zero dry matter' => ['dry_matter', '0'],
            '100 percent moisture' => ['moisture', '100'],
        ];
    }

    #[DataProvider('zeroDryMatterValues')]
    public function test_zero_dry_matter_is_a_known_bound(string $code, string $value): void
    {
        $result = $this->assess([$this->row('sugar', '10'), $this->row('starch', '20'), $this->row($code, $value)]);
        $this->assertSame('insufficient_data', $result['status']);
        $this->assertNull($result['sugar_starch']);
        $this->assertNull($result['score_cap']);
        $this->assertNull($result['automatic_score']);
        $this->assertSame([], $result['applied_caps']);
        $this->assertNotEmpty($result['missing_data']);

        $zero = $this->assess([$this->row('sugar', '0'), $this->row('starch', '0'), $this->row($code, $value)]);
        $this->assertSame('0', $zero['sugar_starch']['lower']);
        $this->assertTrue($zero['sugar_starch']['exact']);
        $this->assertSame([], $zero['missing_data']);
        $this->assertNull($zero['automatic_score']);
    }

    #[DataProvider('zeroDryMatterValues')]
    public function test_zero_dry_matter_cannot_normalize_dry_matter_percentages(string $code, string $value): void
    {
        $result = $this->assess([$this->row('sugar', '10', 'dry_matter'), $this->row('starch', '20', 'dry_matter'), $this->row($code, $value)]);
        $this->assertSame('insufficient_data', $result['status']);
        $this->assertNull($result['sugar_starch']);
        $this->assertNull($result['automatic_score']);
        $this->assertNotEmpty($result['missing_data']);
    }

    public function test_missing_or_consistent_dry_matter_allows_product_basis_assessment(): void
    {
        foreach ([[], [$this->row('moisture', '20'), $this->row('dry_matter', '800', unit: 'g/kg')]] as $rows) {
            $result = $this->assess([$this->row('sugar', '10'), $this->row('starch', '20'), ...$rows]);
            $this->assertSame('30', $result['sugar_starch']['lower']);
            $this->assertSame('0', $result['automatic_score']);
            $this->assertSame([], $result['missing_data']);
        }
    }

    public static function dryMatterUpperBounds(): array
    {
        return [
            'minimum moisture' => ['moisture', '80', '%', 'minimum', false],
            'maximum dry matter' => ['dry_matter', '20', '%', 'maximum', false],
            'strict minimum moisture' => ['moisture', '80', '%', 'greater_than', true],
            'strict maximum dry matter' => ['dry_matter', '20', '%', 'less_than', true],
            'minimum moisture in g/kg' => ['moisture', '800', 'g/kg', 'minimum', false],
            'maximum dry matter in g/kg' => ['dry_matter', '200', 'g/kg', 'maximum', false],
        ];
    }

    #[DataProvider('dryMatterUpperBounds')]
    public function test_moisture_and_dry_matter_bounds_limit_combined_nutrients(string $code, string $value, string $unit, string $qualifier, bool $open): void
    {
        $bound = $this->row($code, $value, unit: $unit, qualifier: $qualifier);
        $impossible = $this->assess([$this->row('sugar', '10'), $this->row('starch', '20'), $bound]);
        $this->assertSame('insufficient_data', $impossible['status']);
        $this->assertNull($impossible['sugar_starch']);
        $this->assertNull($impossible['automatic_score']);
        $this->assertSame([], $impossible['applied_caps']);
        $this->assertNotEmpty($impossible['missing_data']);

        $boundary = $this->assess([$this->row('sugar', '10'), $this->row('starch', '10'), $bound]);
        if ($open) {
            $this->assertNull($boundary['sugar_starch']);
            $this->assertNull($boundary['automatic_score']);
            $this->assertNotEmpty($boundary['missing_data']);
        } else {
            $this->assertSame('20', $boundary['sugar_starch']['lower']);
            $this->assertSame('0', $boundary['automatic_score']);
            $this->assertSame([], $boundary['missing_data']);
        }

        $possible = $this->assess([$this->row('sugar', '8'), $this->row('starch', '8'), $bound]);
        $this->assertSame('16', $possible['sugar_starch']['lower']);
        $this->assertSame('4', $possible['score_cap']);
        $this->assertSame([], $possible['missing_data']);

        $range = $this->assess([$this->row('sugar', '8', qualifier: 'minimum'), $this->row('starch', '8'), $bound]);
        $this->assertSame(['lower' => '16', 'upper' => '20', 'lower_open' => false, 'upper_open' => $open, 'exact' => false], $range['sugar_starch']);

        $matchingUpper = $this->assess([$this->row('sugar', '10', qualifier: 'maximum'), $this->row('starch', '10'), $bound]);
        $this->assertSame($open, $matchingUpper['sugar_starch']['upper_open']);

        $dry = $this->assess([$this->row('sugar', '10', 'dry_matter'), $this->row('starch', '20', 'dry_matter'), $bound]);
        $this->assertNull($dry['sugar_starch']);
        $this->assertNotEmpty($dry['missing_data']);
    }

    public static function conflictingNutrientBasesWithBoundedDryMatter(): array
    {
        return [
            'sugar with minimum moisture' => ['sugar', '30', '10', 'moisture', '10', 'minimum'],
            'starch with maximum dry matter' => ['starch', '30', '10', 'dry_matter', '90', 'maximum'],
            'sugar at excluded dry matter boundary' => ['sugar', '30', '50', 'dry_matter', '60', 'less_than'],
            'starch at excluded moisture boundary' => ['starch', '30', '50', 'moisture', '40', 'greater_than'],
            'sugar below minimum dry matter conversion' => ['sugar', '20', '50', 'dry_matter', '80', 'minimum'],
            'starch below maximum moisture conversion' => ['starch', '20', '50', 'moisture', '20', 'maximum'],
        ];
    }

    #[DataProvider('conflictingNutrientBasesWithBoundedDryMatter')]
    public function test_conflicting_nutrient_bases_with_bounded_dry_matter_are_unresolved(string $code, string $productValue, string $dryValue, string $boundCode, string $boundValue, string $qualifier): void
    {
        $nutrients = [$this->row($code, $productValue), $this->row($code, $dryValue, 'dry_matter')];
        foreach ([$nutrients, array_reverse($nutrients)] as $rows) {
            $result = $this->assess([...$rows,
                $this->row($code === 'sugar' ? 'starch' : 'sugar', '0'),
                $this->row($boundCode, $boundValue, qualifier: $qualifier)]);

            $this->assertSame('insufficient_data', $result['status']);
            $this->assertNull($result['sugar_starch']);
            $this->assertNull($result['score_cap']);
            $this->assertNull($result['automatic_score']);
            $this->assertSame([], $result['applied_caps']);
            $this->assertNotEmpty($result['missing_data']);
        }
    }

    public function test_mixed_basis_nutrients_require_exact_dry_matter_even_when_potentially_compatible(): void
    {
        $rows = [$this->row('sugar', '30'), $this->row('sugar', '50', 'dry_matter'),
            $this->row('starch', '0'), $this->row('moisture', '10', qualifier: 'minimum')];
        $unresolved = $this->assess($rows);
        $this->assertSame('insufficient_data', $unresolved['status']);
        $this->assertNull($unresolved['sugar_starch']);
        $this->assertNull($unresolved['automatic_score']);
        $this->assertNotEmpty($unresolved['missing_data']);

        $resolved = $this->assess([...$rows, $this->row('dry_matter', '60')]);
        $this->assertSame('30', $resolved['sugar_starch']['lower']);
        $this->assertSame('0', $resolved['automatic_score']);
        $this->assertSame([], $resolved['missing_data']);
    }

    public function test_dry_matter_bounds_are_intersected_with_exact_values_and_each_other(): void
    {
        $sugarStarch = [$this->row('sugar', '4'), $this->row('starch', '4')];
        $minimumMoisture = $this->row('moisture', '80', qualifier: 'minimum');
        $maximumDryMatter = $this->row('dry_matter', '20', qualifier: 'maximum');
        foreach ([
            [$minimumMoisture, $this->row('dry_matter', '30')],
            [$this->row('dry_matter', '30'), $minimumMoisture],
            [$maximumDryMatter, $this->row('moisture', '70', qualifier: 'maximum')],
            [$this->row('dry_matter', '20', qualifier: 'minimum'), $this->row('moisture', '80', qualifier: 'greater_than')],
        ] as $bounds) {
            $result = $this->assess([...$sugarStarch, ...$bounds]);
            $this->assertSame('insufficient_data', $result['status']);
            $this->assertNull($result['sugar_starch']);
            $this->assertSame([], $result['applied_caps']);
        }

        $consistent = $this->assess([...$sugarStarch, $minimumMoisture, $this->row('dry_matter', '10')]);
        $this->assertSame('8', $consistent['sugar_starch']['lower']);
        $this->assertSame([], $consistent['missing_data']);

        $exact = $this->assess([$this->row('sugar', '10', 'dry_matter'), $this->row('starch', '20', 'dry_matter'),
            $maximumDryMatter, $this->row('moisture', '80', qualifier: 'maximum')]);
        $this->assertSame('6', $exact['sugar_starch']['lower']);
        $this->assertSame('20', $exact['normalization'][0]['dry_matter_percent']);
        $this->assertSame([], $exact['missing_data']);
    }

    public function test_combined_upper_bound_is_limited_to_available_dry_matter(): void
    {
        $result = $this->assess([
            $this->row('sugar', '10', 'dry_matter', qualifier: 'minimum'),
            $this->row('starch', '10', 'dry_matter', qualifier: 'greater_than'),
            $this->row('moisture', '20'),
        ]);

        $this->assertSame(['lower' => '16', 'upper' => '80', 'lower_open' => true, 'upper_open' => false, 'exact' => false], $result['sugar_starch']);
        $this->assertSame('4', $result['score_cap']);
        $this->assertNull($result['automatic_score']);
        $this->assertNotEmpty($result['missing_data']);
    }

    public function test_selenium_caps_require_evidence_and_total_is_not_addition(): void
    {
        $rows = [$this->row('sugar', '3'), $this->row('starch', '3'), $this->row('selenium_total', '0.3', 'product', 'mg/kg')];
        $this->assertNull($this->assess($rows)['score_cap']);
        $this->assertNull($this->assess($rows, [['selenium_form' => 'organic', 'evidence' => '']])['score_cap']);
        $organic = ['selenium_form' => 'organic', 'evidence' => 'Etiket: seleniumgist'];
        $inorganic = ['selenium_form' => 'inorganic', 'evidence' => 'Etiket: natriumseleniet', 'added_selenium' => 'confirmed'];
        $unknown = ['selenium_form' => 'unknown', 'evidence' => 'Fabrikant bevestigt toegevoegd selenium', 'added_selenium' => 'confirmed'];
        $this->assertSame('3', $this->assess($rows, [$organic])['score_cap']);
        $this->assertNull($this->assess($rows, [$inorganic])['score_cap']);
        $this->assertSame('5.5', $this->assess($rows, [$unknown])['score_cap']);
        $this->assertSame('3', $this->assess($rows, [$organic, $unknown, $inorganic])['score_cap']);
        $high = [$this->row('sugar', '8'), $this->row('starch', '8')];
        $this->assertSame('3', $this->assess($high, [$organic])['score_cap']);
        $this->assertSame('4', $this->assess($high, [$unknown])['score_cap']);
    }

    public function test_configured_policy_values_are_used_without_ingredient_penalties(): void
    {
        $policy = $this->policy();
        $policy['sugar_starch_caps'] = [['threshold' => 18, 'inclusive' => true, 'max_score' => 2]];
        $result = $this->assess([$this->row('sugar', '8'), $this->row('starch', '10')], [['label' => 'Olie of melasse']], $policy);
        $this->assertSame('2', $result['score_cap']);
        $this->assertNull($result['automatic_score']);
        $this->assertCount(1, $result['applied_caps']);
    }

    public function test_supplied_calibration_examples_preserve_reference_scores_as_human_opinions(): void
    {
        $examples = json_decode(file_get_contents(__DIR__.'/../Fixtures/feed-calibration-2026-10-23.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($examples as $example) {
            $result = (new FeedAssessmentEngine)->evaluate($example['data'], $this->policy());
            $this->assertSame($example['expected_automatic_score'], $result['automatic_score'], $example['name']);
        }
    }
}
