<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class IntakeAge
{
    public function fromDate(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
            [, $year, $month, $day] = $parts;
        } elseif (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $value, $parts)) {
            [, $day, $month, $year] = $parts;
        } else {
            return null;
        }
        if ((int) $year < 1000 || ! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }
        $date = CarbonImmutable::create((int) $year, (int) $month, (int) $day)->startOfDay();

        return $date->isFuture() ? null : (int) $date->diffInYears(CarbonImmutable::today());
    }

    public function answered(mixed $age, mixed $date): bool
    {
        if ($date !== null && $date !== '') {
            return $this->fromDate($date) !== null;
        }

        return (is_int($age) && $age >= 0) || (is_string($age) && preg_match('/^\d+$/D', $age) === 1);
    }
}
