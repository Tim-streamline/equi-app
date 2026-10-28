<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class IntakePhotoDimensions implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return; // The file/image rules report invalid uploads.
        }
        $size = @getimagesize($value->getPathname());
        if ($size && (max($size[0], $size[1]) > 7680 || min($size[0], $size[1]) > 4320)) {
            $fail('De foto is te groot. Maximaal 7680 × 4320 pixels (of 4320 × 7680 voor een staande foto).');
        }
    }
}
