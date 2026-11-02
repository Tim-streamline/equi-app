<?php

namespace App\Support;

use App\Models\IntakeField;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Stable option identities and explicit answer conditions, with a legacy client projection. */
class IntakeAnswerConditions
{
    public function prepare(IntakeField $field): void
    {
        // Historical migrations also use this model before these columns exist.
        if (! Schema::hasColumn('intake_fields', 'option_keys')) {
            return;
        }
        if ($field->isDirty('section_id')) {
            $field->unsetRelation('section');
        }
        $oldKeys = json_decode($field->getRawOriginal('option_keys') ?? 'null', true) ?? [];
        $keys = $this->keys($field->options ?? [], $field->option_keys, $oldKeys);
        $this->validateKeys($keys, $field->options ?? []);
        $aliases = $field->answer_aliases ?? [];
        foreach ($oldKeys as $old => $key) {
            $current = array_search($key, $keys, true);
            if ($current !== false && $current !== $old) {
                foreach ($aliases as $alias => $target) {
                    if ($target === $old) {
                        $aliases[$alias] = $current;
                    }
                }
                $aliases[$old] = $current;
            }
        }
        foreach (array_keys($keys) as $answer) {
            unset($aliases[$answer]);
        }
        if ($aliases !== ($field->answer_aliases ?? [])) {
            $field->answer_aliases = $aliases;
        }
        $field->option_keys = $keys;
        if (! $field->isDirty('visibility_rule') && ! $field->isDirty('show_if')) {
            return;
        }
        if (! $field->isDirty('visibility_rule') && $field->show_if === null) {
            $field->visibility_rule = null;

            return;
        }
        $section = $field->section;
        $catalog = $this->catalog($section->questionnaire->sections()->with('fields')->get());
        $catalog[($field->answer_section_key ?? $section->key).'.'.$field->key] = [
            'keys' => $keys, 'options' => $field->options ?? [], 'aliases' => $aliases,
            'active' => $section->active && $field->active,
        ];
        if ($field->isDirty('visibility_rule') && ! $field->syncingDefinition) {
            $field->show_if = $this->compile($field->visibility_rule, $this->references($catalog));
        } elseif (! $field->isDirty('visibility_rule') && ($field->isDirty('show_if') || ! $field->exists)) {
            $field->visibility_rule = $this->migrate($field->show_if, $section->key, $catalog, $section->questionnaire->none_options ?? []);
        }
    }

    public function initialKeys(array $options, string $scope): array
    {
        $keys = [];
        foreach ($options as $option) {
            $keys[$option] = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://equi-app.online/intake/'.$scope.'/'.$option);
        }

        return $keys;
    }

    public function keys(array $options, ?array $supplied, ?array $existing = null): array
    {
        $keys = [];
        foreach ($options as $option) {
            $keys[$option] = $supplied[$option] ?? $existing[$option] ?? (string) Str::uuid();
        }

        return $keys;
    }

    public function catalog(iterable $sections): array
    {
        $fields = [];
        foreach ($sections as $section) {
            foreach ($section->fields as $field) {
                $fields[($field->answer_section_key ?? $section->key).'.'.$field->key] = [
                    'keys' => $field->option_keys ?? [], 'options' => $field->options ?? [],
                    'aliases' => $field->answer_aliases ?? [], 'active' => $section->active && $field->active,
                ];
            }
        }

        return $fields;
    }

    public function references(array $catalog): array
    {
        $references = [];
        foreach ($catalog as $path => $field) {
            if (! $field['active']) {
                continue;
            }
            foreach ($field['keys'] as $answer => $key) {
                $values = [(string) $answer];
                foreach ($field['aliases'] as $old => $current) {
                    if ($current === $answer) {
                        $values[] = (string) $old;
                    }
                }
                $references[$key] = [$path => $values];
            }
        }

        return $references;
    }

    /** Freeze generic count rules into explicit choices; preserve numeric/free-text conditions. */
    public function migrate(?array $rule, string $section, array $catalog, array $none): ?array
    {
        if ($rule === null) {
            return null;
        }
        $parts = [];
        foreach ($rule as $key => $value) {
            if ($key === '$all' || $key === '$any') {
                $parts[] = [$key => array_map(fn ($child) => $this->migrate($child, $section, $catalog, $none), $value)];

                continue;
            }
            if ($key === '$not') {
                $parts[] = ['$not' => $this->migrate($value, $section, $catalog, $none)];

                continue;
            }
            $path = str_contains($key, '.') ? $key : $section.'.'.$key;
            $field = $catalog[$path] ?? null;
            $values = is_string($value) ? [$value] : $value;
            if (! $field || ! is_array($values) || ! array_is_list($values)) {
                $parts[] = [$key => $value];

                continue;
            }
            $minimum = in_array('multi-checked', $values, true) ? 2 : (in_array('any-checked', $values, true) ? 1 : 0);
            if ($minimum) {
                $excluded = array_map(fn ($v) => mb_strtolower(trim($v)), $none);
                $values = array_values(array_filter($field['options'], fn ($v) => ! in_array(mb_strtolower(trim($v)), $excluded, true)));
            }
            $leaves = [];
            foreach ($values as $answer) {
                $canonical = $field['aliases'][$answer] ?? $answer;
                if (! isset($field['keys'][$canonical])) {
                    break;
                }
                $leaves[$field['keys'][$canonical]] = ['$answer' => $field['keys'][$canonical]];
            }
            // Unknown historical choices retain their original, exact rule.
            if (! $values || (! $minimum && count($leaves) < count(array_unique($values)))) {
                $parts[] = [$key => $value];

                continue;
            }
            $leaves = array_values($leaves);
            if ($minimum === 2) {
                $pairs = [];
                foreach ($leaves as $i => $left) {
                    foreach (array_slice($leaves, $i + 1) as $right) {
                        $pairs[] = ['$all' => [$left, $right]];
                    }
                }
                $parts[] = ['$any' => $pairs];
            } else {
                $parts[] = count($leaves) === 1 ? $leaves[0] : ['$any' => $leaves];
            }
        }

        return count($parts) === 1 ? $parts[0] : ['$all' => $parts];
    }

    public function compile(?array $rule, array $references): ?array
    {
        if ($rule === null) {
            return null;
        }
        $result = [];
        foreach ($rule as $key => $value) {
            if ($key === '$answer') {
                $result = [...$result, ...($references[$value] ?? ['__missing_answer__' => '__never__'])];
            } elseif ($key === '$all' || $key === '$any') {
                $result[$key] = array_map(fn ($child) => $this->compile($child, $references), $value);
            } elseif ($key === '$not') {
                $result[$key] = $this->compile($value, $references);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function validateKeys(mixed $keys, array $options): void
    {
        if (! is_array($keys) || count($options) !== count(array_unique($options)) || count($keys) !== count(array_unique($options)) || count(array_unique($keys)) !== count($keys)) {
            throw ValidationException::withMessages(['option_keys' => 'Elke antwoordoptie moet een eigen unieke antwoordsleutel hebben.']);
        }
        foreach ($keys as $answer => $key) {
            if (! in_array((string) $answer, $options, true) || ! is_string($key) || ! Str::isUuid($key)) {
                throw ValidationException::withMessages(['option_keys' => 'Gebruik een unieke UUID per bestaande antwoordoptie.']);
            }
        }
    }

    public function answerKeys(?array $rule): array
    {
        $keys = [];
        foreach ($rule ?? [] as $key => $value) {
            if ($key === '$answer') {
                $keys[] = $value;
            } elseif ($key === '$all' || $key === '$any') {
                foreach ($value as $child) {
                    $keys = [...$keys, ...$this->answerKeys($child)];
                }
            } elseif ($key === '$not') {
                $keys = [...$keys, ...$this->answerKeys($value)];
            }
        }

        return $keys;
    }

    public function validateDependencies(?array $rule, iterable $sections, ?IntakeField $editing): void
    {
        $owners = [];
        $fields = [];
        foreach ($sections as $section) {
            foreach ($section->fields as $field) {
                $fields[$field->id] = $field;
                if ($section->active && $field->active) {
                    foreach ($field->option_keys ?? [] as $key) {
                        $owners[$key] = $field->id;
                    }
                }
            }
        }
        if (array_diff($this->answerKeys($rule), array_keys($owners))) {
            throw ValidationException::withMessages(['show_if_json' => 'Kies bestaande antwoorden van actieve vragen in deze intake.']);
        }
        if (! $editing) {
            return;
        }
        $visiting = [];
        $visited = [];
        $visit = function ($id) use (&$visit, &$visiting, &$visited, $fields, $owners, $editing, $rule): void {
            if (isset($visiting[$id])) {
                throw ValidationException::withMessages(['show_if_json' => 'Deze voorwaarden vormen een cirkel: vragen mogen niet van zichzelf afhangen.']);
            }
            if (isset($visited[$id])) {
                return;
            }
            $visiting[$id] = true;
            $condition = $id === $editing->id ? $rule : ($fields[$id]->visibility_rule ?? null);
            foreach ($this->answerKeys($condition) as $key) {
                if (isset($owners[$key])) {
                    $visit($owners[$key]);
                }
            }
            unset($visiting[$id]);
            $visited[$id] = true;
        };
        $visit($editing->id);
    }

    /** Keep old app versions functional after a label or answer namespace changes. */
    public function refreshProjections(IntakeField $field): void
    {
        $sections = $field->section->questionnaire->sections()->with('fields')->get();
        $references = $this->references($this->catalog($sections));
        $affectedKeys = array_unique([...array_values($field->option_keys ?? []), ...array_values(json_decode($field->getRawOriginal('option_keys') ?? '[]', true) ?? [])]);
        foreach ($sections as $section) {
            foreach ($section->fields as $dependent) {
                if ($dependent->id === $field->id || ! array_intersect($this->answerKeys($dependent->visibility_rule), $affectedKeys)) {
                    continue;
                }
                $projection = $this->compile($dependent->visibility_rule, $references);
                if ($projection !== $dependent->show_if) {
                    DB::table('intake_fields')->where('id', $dependent->id)->update(['show_if' => json_encode($projection, JSON_THROW_ON_ERROR)]);
                }
            }
        }
    }
}
