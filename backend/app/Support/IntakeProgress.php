<?php

namespace App\Support;

use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;

class IntakeProgress
{
    public function forBooking(IntakeBooking $booking): array
    {
        $answers = [];
        foreach ($booking->answers as $answer) {
            $answers[$answer->section_id][$answer->field_id] = json_decode($answer->value ?? 'null', true);
        }
        $questionnaire = IntakeQuestionnaire::where('slug', 'protocol-intake')->where('active', true)
            ->with(['sections' => fn ($q) => $q->where('active', true), 'sections.fields' => fn ($q) => $q->where('active', true)])->first();
        $missing = [];
        $total = 0;
        $review = app(IntakeReview::class);
        $photos = app(IntakePhotoRules::class);
        foreach ($questionnaire?->sections ?? [] as $section) {
            $sectionAnswers = $review->sectionAnswers($section, $answers);
            foreach ($section->fields as $field) {
                $value = $sectionAnswers[$field->key] ?? null;
                if ($field->type === 'sectionhead') {
                    continue;
                }
                if (! $review->visible($field->show_if, $sectionAnswers, $answers, $questionnaire->none_options ?? [])) {
                    continue;
                }
                if (($field->photo_config['existing_only'] ?? false) && ! $photos->references($value)) {
                    continue;
                }
                if ($field->optional && ! ($field->type === 'photo' && ($field->photo_config['year_required'] ?? false) && $photos->references($value))) {
                    continue;
                }
                $total++;
                $answered = ! $review->empty($value);
                if ($field->type === 'text') {
                    $answered = $field->lines && is_array($value)
                        ? count(array_filter($value, fn ($line) => is_string($line) && trim($line) !== '')) > 0
                        : is_string($value) && trim($value) !== '';
                }
                if ($field->type === 'number' && $field->age_config) {
                    $date = $answers[$field->answer_section_key ?? $section->key][$field->age_config['date_field'] ?? ''] ?? null;
                    $answered = app(IntakeAge::class)->answered($value, $date);
                }
                if ($field->type === 'radio') {
                    $answered = is_scalar($value) && in_array((string) $value, $field->options ?? [], true);
                }
                if ($field->type === 'multi') {
                    $answered = is_array($value) && count(array_intersect(array_filter($value, 'is_string'), $field->options ?? [])) > 0;
                }
                if ($field->type === 'repeater') {
                    $answered = is_array($value) && count(array_filter($value, fn ($row) => is_array($row) && count(array_filter($row, fn ($v, $key) => ! str_starts_with((string) $key, '_') && is_string($v) && trim($v) !== '', ARRAY_FILTER_USE_BOTH)) > 0)) > 0;
                }
                if ($field->type === 'photo') {
                    $answered = $photos->answered($field, $value, $answers);
                }
                if (! $answered) {
                    $missing[] = ['section' => $section->key, 'field' => $field->key];
                }
            }
        }
        $answered = $total - count($missing);

        return ['total' => $total, 'answered' => $answered, 'missing' => $missing,
            'stage' => $total > 0 && ! $missing ? 'ready' : ($total > 0 && $answered / $total >= .9 ? 'almost' : 'continue')];
    }
}
