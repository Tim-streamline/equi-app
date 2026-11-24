<?php

namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Admin metadata only: never changes answer keys, values or protocol decisions. */
class IntakeLabels
{
    public function definitions(array $field, string $path = ''): array
    {
        $nodes = [];
        if (($field['type'] ?? '') !== 'sectionhead') {
            $nodes[] = ['key' => $path, 'title' => $field['label'] ?? '', 'options' => $field['options'] ?? []];
        }
        foreach ($field['repeater_sub'] ?? $field['sub'] ?? [] as $sub) {
            $nodes = array_merge($nodes, $this->definitions($sub, $path === '' ? $sub['id'] : $path.'/'.$sub['id']));
        }
        return $nodes;
    }

    public function complete(array $field, string $prefix, ?array $existing): array
    {
        $saved = collect($existing ?? [])->keyBy('key');
        $nodes = $this->definitions($field);
        $used = [];
        $questionLabels = [];
        $answerLabels = [];

        // Custom labels keep their meaning, even when an earlier node would generate the same default.
        foreach ($nodes as $node) {
            $key = $node['key'];
            $previous = $saved->get($key, []);
            if (isset($previous['label'])) {
                $questionLabels[$key] = $previous['label'];
                $used[$previous['label']] = true;
            }
            $answers = collect($previous['answers'] ?? [])->pluck('label', 'answer');
            foreach ($node['options'] as $answer) {
                if (isset($answers[$answer])) {
                    $answerLabels[$key][$answer] = $answers[$answer];
                    $used[$answers[$answer]] = true;
                }
            }
        }
        foreach ($nodes as $node) {
            $key = $node['key'];
            $questionLabels[$key] ??= $this->allocate($prefix.($key === '' ? '' : '_'.$key), $used);
        }
        $result = [];
        foreach ($nodes as $node) {
            $key = $node['key'];
            $label = $questionLabels[$key];
            $rows = [];
            foreach ($node['options'] as $answer) {
                $rows[] = ['answer' => $answer, 'label' => $answerLabels[$key][$answer] ?? $this->allocate($label.'_'.$answer, $used)];
            }
            $result[] = ['key' => $key, 'label' => $label, 'answers' => $rows];
        }

        return $result;
    }

    public function validate(array $config): void
    {
        $seen = [];
        foreach ($config as $node) {
            foreach (array_merge([$node['label']], array_column($node['answers'], 'label')) as $label) {
                if (! is_string($label) || ! preg_match('/^[a-z][a-z0-9_]{0,239}$/', $label)) {
                    throw ValidationException::withMessages(['label_config' => 'Gebruik labels van maximaal 240 tekens: kleine letters, cijfers en underscores, beginnend met een letter.']);
                }
                if (in_array($label, $seen, true)) {
                    throw ValidationException::withMessages(['label_config' => 'Geef iedere vraag en antwoordoptie binnen deze vraag een verschillend label.']);
                }
                $seen[] = $label;
            }
        }
    }

    private function allocate(string $value, array &$used): string
    {
        $base = $this->slug($value);
        $label = $base;
        for ($suffix = 2; isset($used[$label]); $suffix++) {
            $label = $base.'_'.$suffix;
        }
        $used[$label] = true;

        return $label;
    }

    private function slug(string $value): string
    {
        // Preserve distinctions that ordinary slugification would discard.
        $value = str_replace(['<', '>', '+', '×'], [' minder_dan ', ' meer_dan ', ' plus ', ' x '], $value);
        $slug = Str::slug($value, '_');
        if (! preg_match('/^[a-z]/', $slug)) $slug = 'vraag_'.$slug;
        return strlen($slug) > 220 ? substr($slug, 0, 209).'_'.substr(sha1($slug), 0, 10) : $slug;
    }
}
