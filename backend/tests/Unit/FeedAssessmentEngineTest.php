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
