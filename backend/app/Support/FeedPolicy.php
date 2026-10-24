<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;

class FeedPolicy
{
    public const SCHEMA_VERSION = 2;

    public static function capRules(bool $required = true): array
    {
        return [
            'sugar_starch_caps' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:20'],
            'sugar_starch_caps.*' => ['array:threshold,inclusive,max_score'],
            'sugar_starch_caps.*.threshold' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,6'],
            'sugar_starch_caps.*.inclusive' => ['required', 'boolean'],
            'sugar_starch_caps.*.max_score' => ['required', 'numeric', 'min:0', 'max:10', 'decimal:0,2'],
            'organic_selenium_cap' => ['required', 'numeric', 'min:0', 'max:10', 'decimal:0,2'],
            'unknown_added_selenium_cap' => ['required', 'numeric', 'min:0', 'max:10', 'decimal:0,2'],
        ];
    }

    public static function validate(array $data): void
    {
        Validator::make($data, self::capRules())->validate();
    }
}
