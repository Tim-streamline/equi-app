<?php

namespace App\Support;

use App\Models\IntakeField;

/** Shared, CMS-configured photo semantics for review and reminder progress. */
class IntakePhotoRules
{
    public function references(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, fn ($v) => is_string($v) && preg_match('/^attachment:[^:]+:/', $v))) : [];
    }

    public function notApplicable(IntakeField $field, array $answers): bool
    {
        $config = $field->photo_config ?? [];
        $key = $config['na_key'] ?? 'na:'.($field->answer_section_key ?? $field->section->key).':'.$field->key;

        return ($config['allow_na'] ?? false) && ($answers['__photos'][$key] ?? null) === 'yes';
    }

    public function answered(IntakeField $field, mixed $value, array $answers): bool
    {
        if ($this->notApplicable($field, $answers)) {
            return true;
        }
        $refs = $this->fieldReferences($field, $value, $answers);
        if (! $refs) {
            return false;
        }
        $config = $field->photo_config ?? [];
        foreach ($refs as $ref) {
            $id = explode(':', $ref)[1];
            if ($config['year_required'] ?? false) {
                $year = $answers['__photos']['year-'.$id] ?? null;
                if (! is_string($year) || ! preg_match('/^\d{4}$/', $year) || (int) $year < 1000 || (int) $year > (int) now()->year) {
                    return false;
                }
            }
        }
        foreach ($config['slots'] ?? [] as $slot) {
            if (! $this->slotReferences($field, $slot, $value, $answers)) {
                return false;
            }
        }

        return true;
    }

    public function slotReferences(IntakeField $field, array $slot, mixed $value, array $answers): array
    {
        if (isset($slot['answer_field'])) {
            $refs = $this->references($slot['answer_field'] === $field->key ? $value : ($answers[$field->answer_section_key ?? $field->section->key][$slot['answer_field']] ?? null));

            return isset($slot['answer_slot'])
                ? array_values(array_filter($refs, fn ($ref) => ($answers['__photos']['slot-'.explode(':', $ref)[1]] ?? null) === $slot['answer_slot']))
                : $refs;
        }

        return array_values(array_filter($this->references($value), fn ($ref) => ($answers['__photos']['slot-'.explode(':', $ref)[1]] ?? null) === $slot['id']));
    }

    public function fieldReferences(IntakeField $field, mixed $value, array $answers): array
    {
        $refs = $this->references($value);
        foreach ($field->photo_config['slots'] ?? [] as $slot) {
            $refs = array_merge($refs, $this->slotReferences($field, $slot, $value, $answers));
        }
        if ($legacy = $field->photo_config['legacy_field'] ?? null) {
            $refs = array_merge($refs, $this->references($answers[$field->answer_section_key ?? $field->section->key][$legacy] ?? null));
        }

        return array_values(array_unique($refs));
    }
}
