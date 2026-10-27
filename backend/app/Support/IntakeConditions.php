<?php

namespace App\Support;

/** Backwards-compatible, nested visibility rules shared by intake administration and review. */
class IntakeConditions
{
    /** Moving a question must not change where its existing parent answers are read. */
    public function qualify(?array $rule, callable $source, ?callable $aliases = null): ?array
    {
        if ($rule === null) return null;
        $result = [];
        foreach ($rule as $key => $value) {
            if ($key === '$all' || $key === '$any') $result[$key] = array_map(fn ($child) => $this->qualify($child, $source, $aliases), $value);
            elseif ($key === '$not') $result[$key] = $this->qualify($value, $source, $aliases);
            elseif (str_contains($key, '.')) $result[$key] = $value;
            else {
                // Qualified references read raw answers instead of the section's alias-normalized values.
                if ($aliases && (is_string($value) || array_is_list($value))) {
                    $options = (array) $value;
                    if (! array_intersect($options, ['any-checked', 'multi-checked'])) {
                        foreach ($aliases($key) as $legacy => $canonical) {
                            if (in_array($canonical, (array) $value, true) && ! in_array($legacy, $options, true)) $options[] = $legacy;
                        }
                        if ($options !== (array) $value) $value = $options;
                    }
                }
                $result[$source($key).'.'.$key] = $value;
            }
        }
        return $result;
    }

    /** Whether a rule uses syntax that app versions before 27 October 2026 cannot evaluate. */
    public function grouped(mixed $rule): bool
    {
        if (! is_array($rule)) return false;
        foreach ($rule as $key => $value) {
            if (in_array($key, ['$all', '$any', '$not'], true)) return true;
            if (is_array($value) && ! array_is_list($value) && array_key_exists('gte', $value)) return true;
        }
        return false;
    }

    public function valid(mixed $rule, int $depth = 0): bool
    {
        if ($rule === null) return true;
        if ($depth > 12 || ! is_array($rule) || array_is_list($rule)) return false;
        foreach ($rule as $key => $value) {
            if (in_array($key, ['$all', '$any'], true)) {
                if (! is_array($value) || ! array_is_list($value) || ! $value) return false;
                foreach ($value as $child) if ($child === null || ! $this->valid($child, $depth + 1)) return false;
            } elseif ($key === '$not') {
                if ($value === null || ! $this->valid($value, $depth + 1)) return false;
            } elseif (! preg_match('/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)?$/', $key)) {
                return false;
            } elseif (is_array($value) && ! array_is_list($value)) {
                if (array_keys($value) !== ['gte'] || ! is_numeric($value['gte'])) return false;
            } elseif (! is_string($value) && (! is_array($value) || ! $value || array_filter($value, fn ($v) => ! is_string($v)))) {
                return false;
            }
        }
        return true;
    }
}
