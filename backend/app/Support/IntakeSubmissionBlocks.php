<?php

namespace App\Support;

use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Submission is determined by the answers in this intake, never recovery declarations. */
class IntakeSubmissionBlocks
{
    public function validate(?array $rules): void
    {
        if ($rules === null) return;
        Validator::make(['rules' => $rules], [
            'rules' => ['array', 'list'],
            'rules.*' => ['array:answer,message,when'],
            'rules.*.answer' => ['required', 'string', 'max:1000'],
            'rules.*.message' => ['required', 'string', 'max:5000'],
            'rules.*.when' => ['sometimes', 'array'],
            'rules.*.when.*' => ['array', 'list', 'min:1'],
            'rules.*.when.*.*' => ['required', 'string'],
        ])->validate();
    }

    public function forBooking(IntakeBooking $booking): array
    {
        $q = IntakeQuestionnaire::where('slug', 'protocol-intake')->where('active', true)
            ->with(['sections' => fn ($q) => $q->where('active', true), 'sections.fields' => fn ($q) => $q->where('active', true)])->first();
        $answers = [];
        foreach ($booking->answers()->get() as $answer) {
            $answers[$answer->section_id][$answer->field_id] = json_decode($answer->value ?? 'null', true);
        }
        $review = app(IntakeReview::class);
        $blocks = [];
        foreach ($q?->sections ?? [] as $section) {
            $values = $review->sectionAnswers($section, $answers);
            foreach ($section->fields as $field) {
                if (! $review->visible($field->show_if, $values, $answers, $q->none_options ?? [])) continue;
                $selected = $values[$field->key] ?? null;
                foreach ($field->submission_blocks ?? [] as $rule) {
                    $matches = in_array($rule['answer'], is_array($selected) ? $selected : [$selected], true);
                    foreach ($rule['when'] ?? [] as $key => $accepted) {
                        if (! in_array($values[$key] ?? null, $accepted, true)) $matches = false;
                    }
                    if ($matches) $blocks[] = ['section' => $section->key, 'field' => $field->key, 'label' => $field->label, 'answer' => $rule['answer'], 'message' => $rule['message']];
                }
            }
        }

        return $blocks;
    }

    public function assertAllowed(IntakeBooking $booking): void
    {
        $blocks = $this->forBooking($booking);
        if ($blocks) throw ValidationException::withMessages(['intake' => array_values(array_unique(array_column($blocks, 'message')))]);
    }
}
