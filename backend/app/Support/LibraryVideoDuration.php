<?php

namespace App\Support;

class LibraryVideoDuration
{
    // duration_sec is a PostgreSQL integer.
    public const MAX_MINUTES = 35791394;

    public static function minutes(?string $label, ?int $seconds = null): ?float
    {
        $label = trim($label ?? '');
        if ($label === '') {
            $minutes = $seconds && $seconds > 0 ? round($seconds / 60, 2) : null;
        } elseif (preg_match('/^(\d+(?:[.,]\d{1,2})?)\s*(?:min(?:uten|uut|utes|ute|s)?\.?)?(?:\s*[·|]\s*video)?$/iu', $label, $match)) {
            $minutes = (float) str_replace(',', '.', $match[1]);
        } else {
            return null;
        }

        return $minutes > 0 && $minutes <= self::MAX_MINUTES ? $minutes : null;
    }

    public static function label(float $minutes): string
    {
        return rtrim(rtrim(number_format($minutes, 2, '.', ''), '0'), '.').' min';
    }
}
