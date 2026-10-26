<?php

namespace App\Support;

use App\Models\IntakeField;
use Illuminate\Support\Facades\Validator;

class IntakeDates
{
    public const MONTHS = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

    public function normalize(mixed $value, array $config = []): mixed
    {
        if (! is_string($value) || trim($value) === '') return $value;
        $text = trim($value);
        $lower = mb_strtolower($text);
        if (in_array($lower, ['onbekend', 'weet ik niet', 'weet niet', 'unknown'], true)) return ['version' => 1, 'precision' => 'unknown'];
        $legacy = ['version' => 1, 'precision' => 'legacy', 'text' => $text];
        if (($config['mode'] ?? 'single') === 'range') return $legacy;
        if (preg_match('/^(19|20)\d{2}$/D', $text)) return ['version' => 1, 'precision' => 'year', 'year' => (int) $text];
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/D', $text, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return ['version' => 1, 'precision' => 'day', 'year' => (int) $m[1], 'month' => (int) $m[2], 'day' => (int) $m[3]];
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/D', $text, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) return ['version' => 1, 'precision' => 'day', 'year' => (int) $m[3], 'month' => (int) $m[2], 'day' => (int) $m[1]];
        if (preg_match('/^(\d{4})-(\d{1,2})$/D', $text, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) return ['version' => 1, 'precision' => 'month', 'year' => (int) $m[1], 'month' => (int) $m[2]];
        if (preg_match('/^(\d{1,2})[-\/](\d{4})$/D', $text, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) return ['version' => 1, 'precision' => 'month', 'year' => (int) $m[2], 'month' => (int) $m[1]];
        $names = [...self::MONTHS, 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
        if (preg_match('/^([a-z]+)\s+(\d{4})$/D', $lower, $m) && ($index = array_search($m[1], $names, true)) !== false) return ['version' => 1, 'precision' => 'month', 'year' => (int) $m[2], 'month' => $index % 12 + 1];
        return $legacy;
    }

    public function answered(mixed $raw, array $config = []): bool
    {
        $v = $this->normalize($raw, $config);
        if (! is_array($v) || ($v['version'] ?? null) !== 1) return false;
        $precision = $v['precision'] ?? null;
        if ($precision === 'unknown') return ($config['allow_unknown'] ?? true) !== false;
        if ($precision === 'legacy') return is_string($v['text'] ?? null) && trim($v['text']) !== '';
        if ($precision === 'range') {
            if (($config['mode'] ?? 'single') !== 'range') return false;
            $point = array_replace($config, ['mode' => 'single']);
            if (! $this->answered($v['start'] ?? null, $point)) return false;
            if (($v['ongoing'] ?? false) === true) return true;
            if (! $this->answered($v['end'] ?? null, $point)) return false;
            return empty($v['start']['year']) || empty($v['end']['year']) || $v['start']['year'] * 12 + ($v['start']['month'] ?? 1) <= $v['end']['year'] * 12 + ($v['end']['month'] ?? 12);
        }
        if (($config['mode'] ?? 'single') === 'range' || ! in_array($precision, ['month', 'year', 'day'], true)) return false;
        $y = $v['year'] ?? null;
        $m = $v['month'] ?? null;
        if (! is_int($y) || $y < ($config['min_year'] ?? 1900) || $y > 9999) return false;
        if ($precision === 'year' && ($config['allow_year_only'] ?? true) === false) return false;
        if ($precision !== 'year' && (! is_int($m) || $m < 1 || $m > 12)) return false;
        if ($precision === 'day' && (! is_int($v['day'] ?? null) || ! checkdate($m, $v['day'], $y))) return false;
        if (! ($config['allow_future'] ?? false) && ($y > now()->year || ($y === now()->year && ($m ?? 1) > now()->month))) return false;
        return true;
    }

    public function format(mixed $raw): string
    {
        $v = $this->normalize($raw);
        if (! is_array($v)) return is_scalar($v) ? (string) $v : '';
        return match ($v['precision'] ?? '') {
            'unknown' => 'Onbekend', 'legacy' => $v['text'] ?? '',
            'range' => $this->format($v['start'] ?? null).' – '.(($v['ongoing'] ?? false) ? 'heden' : $this->format($v['end'] ?? null)),
            'year' => ($v['year'] ?? '').' (maand onbekend)',
            'month', 'day' => trim((isset($v['day']) ? $v['day'].' ' : '').(self::MONTHS[($v['month'] ?? 0) - 1] ?? '').' '.($v['year'] ?? '')),
            default => '',
        };
    }

    public function forField(IntakeField $field, mixed $value): mixed
    {
        if ($field->type === 'month_year') return $this->normalize($value, $field->date_config ?? []);
        if ($field->type !== 'repeater' || ! is_array($value)) return $value;
        foreach ($value as &$row) {
            if (! is_array($row)) continue;
            foreach ($field->repeater_sub ?? [] as $sub) {
                if (($sub['type'] ?? '') === 'month_year' && isset($row[$sub['id']])) $row[$sub['id']] = $this->normalize($row[$sub['id']], $sub['dateConfig'] ?? []);
            }
        }
        return $value;
    }

    public function validateConfig(mixed $config): void
    {
        Validator::make(['config' => $config], [
            'config' => ['nullable', 'array:mode,allow_future,allow_year_only,allow_unknown,min_year,month_label,year_label,unknown_label,ongoing_label'],
            'config.mode' => ['sometimes', 'in:single,range'], 'config.allow_future' => ['sometimes', 'boolean'],
            'config.allow_year_only' => ['sometimes', 'boolean'], 'config.allow_unknown' => ['sometimes', 'boolean'], 'config.min_year' => ['sometimes', 'integer', 'between:1000,9999'],
            'config.month_label' => ['sometimes', 'string', 'max:100'], 'config.year_label' => ['sometimes', 'string', 'max:100'],
            'config.unknown_label' => ['sometimes', 'string', 'max:100'], 'config.ongoing_label' => ['sometimes', 'string', 'max:100'],
        ])->validate();
    }
}
