<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ShortProtocolText implements ValidationRule
{
    public function __construct(private int $sentences) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // Count sentences, not screen lines; decimal numbers are not sentence boundaries.
        $sentences = preg_split('/[.!?]+(?:[”"\x{2019}]?)(?:\s+|$)/u', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if (count($sentences) > $this->sentences) {
            $fail('Gebruik maximaal '.$this->sentences.' korte '.($this->sentences === 1 ? 'zin.' : 'zinnen.'));
        }
    }
}
