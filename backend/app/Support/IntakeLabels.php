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
        $result = [];
        foreach ($this->definitions($field) as $node) {
            $previous = $saved->get($node['key'], []);
            $label = $previous['label'] ?? $this->slug($prefix.($node['key'] === '' ? '' : '_'.$node['key']));
            $answers = collect($previous['answers'] ?? [])->keyBy('answer');
            $used = [$label];
            $rows = [];
            // Reserve existing labels before allocating defaults for new answers.
            foreach ($node['options'] as $answer) {
                if (isset($answers[$answer]['label'])) $used[] = $answers[$answer]['label'];
            }
            foreach ($node['options'] as $answer) {
                $answerLabel = $answers->get($answer)['label'] ?? null;
                if ($answerLabel === null) {
                    $base = $this->slug($label.'_'.$answer);
                    $answerLabel = $base;
                    $suffix = 2;
                    while (in_array($answerLabel, $used, true)) $answerLabel = $base.'_'.$suffix++;
                    $used[] = $answerLabel;
                }
                $rows[] = ['answer' => $answer, 'label' => $answerLabel];
            }
            $result[] = ['key' => $node['key'], 'label' => $label, 'answers' => $rows];
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

    private function slug(string $value): string
    {
        // Preserve distinctions that ordinary slugification would discard.
        $value = str_replace(['<', '>', '+', '×'], [' minder_dan ', ' meer_dan ', ' plus ', ' x '], $value);
        $slug = Str::slug($value, '_');
        if (! preg_match('/^[a-z]/', $slug)) $slug = 'vraag_'.$slug;
        return strlen($slug) > 220 ? substr($slug, 0, 209).'_'.substr(sha1($slug), 0, 10) : $slug;
    }
}
