<?php

namespace Tests\Unit;

use App\Support\IntakeConditions;
use App\Support\IntakeReview;
use PHPUnit\Framework\TestCase;

class IntakeConditionsTest extends TestCase
{
    public function test_qualifying_nested_conditions_preserves_legacy_matches_and_other_operators(): void
    {
        $conditions = new IntakeConditions;
        $aliases = ['method' => ['Old combined' => 'Combined', 'Old none' => 'None']];
        $rule = ['$all' => [
            ['$any' => [['method' => ['Combined']], ['method' => 'Research']]],
            ['$not' => ['method' => 'None']],
            ['count' => ['gte' => 2]],
            ['signs' => 'any-checked'],
            ['other.method' => 'Combined'],
        ]];
        $moved = $conditions->qualify($rule, fn () => 'original', fn ($key) => $aliases[$key] ?? []);
        $this->assertSame(['original.method' => ['Combined', 'Old combined']], $moved['$all'][0]['$any'][0]);
        $this->assertSame(['$not' => ['original.method' => ['None', 'Old none']]], $moved['$all'][1]);
        $this->assertSame(['original.count' => ['gte' => 2]], $moved['$all'][2]);
        $this->assertSame(['original.signs' => 'any-checked'], $moved['$all'][3]);
        $this->assertSame(['other.method' => 'Combined'], $moved['$all'][4]);
        $this->assertTrue($conditions->valid($moved));
        $this->assertSame($moved, $conditions->qualify($moved, fn () => 'next', fn ($key) => $aliases[$key] ?? []));

        $review = new IntakeReview;
        foreach (['Old combined', 'Combined', 'Research', 'Old none', 'None'] as $answer) {
            $raw = ['method' => $answer, 'count' => 2, 'signs' => ['Present']];
            $normalized = [...$raw, 'method' => $aliases['method'][$answer] ?? $answer];
            $answers = ['original' => $raw, 'other' => ['method' => 'Combined']];
            $this->assertSame($review->visible($rule, $normalized, $answers, []), $review->visible($moved, [], $answers, []), $answer);
        }
    }
}
