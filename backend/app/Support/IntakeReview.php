<?php

namespace App\Support;

use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use App\Models\IntakeSection;

/** One evaluator for the admin review, print output and future protocol generation. */
class IntakeReview
{
    public function forBooking(IntakeBooking $booking): array
    {
        $questionnaire = IntakeQuestionnaire::where('slug', 'protocol-intake')->where('active', true)
            ->with(['sections' => fn ($q) => $q->where('active', true), 'sections.fields' => fn ($q) => $q->where('active', true)])->first();
        $answers = [];
        foreach ($booking->answers as $answer) {
            $decoded = json_decode($answer->value ?? 'null', true);
            $answers[$answer->section_id][$answer->field_id] = json_last_error() === JSON_ERROR_NONE ? $decoded : $answer->value;
        }
        $none = $questionnaire?->none_options ?? ['geen'];
        $sections = [];
        $photos = app(IntakePhotoRules::class);
        $usedMetadata = [];
        $safety = [];
        $triggers = [];
        $attachments = IntakeAttachment::where('booking_id', $booking->id)->get()->keyBy('id');
        foreach ($questionnaire?->sections ?? [] as $section) {
            $rows = [];
            $sectionAnswers = $this->sectionAnswers($section, $answers);
            foreach ($section->fields as $field) {
                if (! $this->visible($field->show_if, $sectionAnswers, $answers, $none)) {
                    continue;
                }
                $value = $sectionAnswers[$field->key] ?? null;
                if (($field->photo_config['existing_only'] ?? false) && ! $photos->references($value)) {
                    continue;
                }
                $answerSection = $field->answer_section_key ?? $section->key;
                $critical = $this->matches($value, $field->critical_if);
                $flag = $critical || $this->flagged($value, $field->flag_if, $none);
                $hits = [];
                foreach ($field->protocol_if ?? [] as $option => $text) {
                    if (($option === 'veiligheid' && $critical) || ($option === 'non-empty' && ! $this->empty($value))
                        || (! in_array($option, ['veiligheid', 'non-empty'], true) && $this->matches($value, $option))) {
                        $hits[] = $text;
                    }
                }
                $row = [
                    'id' => $answerSection.'.'.$field->key, 'key' => $field->key, 'section' => $section->key,
                    'label' => $field->label, 'type' => $field->type, 'unit' => $field->unit,
                    'sub' => $field->repeater_sub ?? [], 'value' => $value,
                    'empty' => $this->empty($value), 'required' => ! $field->optional,
                    'flagged' => $flag, 'critical' => $critical, 'protocol' => $hits,
                    'flagged_options' => array_values(array_filter(is_array($value) ? $value : [], fn ($v) => is_scalar($v) && ($this->matches($v, $field->critical_if) || $this->flagged($v, $field->flag_if, $none)))),
                    'attachments' => [],
                ];
                if (in_array($field->type, ['photo', 'file'])) {
                    foreach (is_array($value) ? $value : [] as $reference) {
                        $id = is_string($reference) ? explode(':', $reference)[1] ?? '' : '';
                        $file = $attachments->get($id);
                        if ($file && $file->section_key === $answerSection && $file->field_key === $field->key) {
                            $row['attachments'][] = ['id' => $file->id, 'name' => $file->name, 'image' => str_starts_with($file->mime, 'image/'),
                                'year' => $answers['__photos']['year-'.$file->id] ?? null, 'url' => route('admin.intake-media', $file->id)];
                        }
                    }
                    $row['empty'] = $row['attachments'] === [];
                }
                if ($field->type === 'photo') {
                    $row['not_applicable'] = $photos->notApplicable($field, $answers);
                    if ($row['not_applicable']) {
                        $row['empty'] = false;
                    }
                    $config = $field->photo_config ?? [];
                    if ($config['allow_na'] ?? false) {
                        $usedMetadata[] = $config['na_key'] ?? 'na:'.$answerSection.':'.$field->key;
                    }
                    $slots = array_column($config['slots'] ?? [], 'label', 'id');
                    foreach ($row['attachments'] as &$photo) {
                        $photo['slot'] = $slots[$answers['__photos']['slot-'.$photo['id']] ?? ''] ?? null;
                        $usedMetadata[] = 'year-'.$photo['id'];
                        $usedMetadata[] = 'slot-'.$photo['id'];
                    }
                    unset($photo);
                }
                $rows[] = $row;
                if ($field->critical_if) {
                    $safety[] = $row;
                }
                if ($hits) {
                    $triggers[] = $row + ['section_title' => $section->title];
                }
            }
            $questions = array_values(array_filter($rows, fn ($r) => $r['type'] !== 'sectionhead'));
            $sections[] = ['id' => $section->key, 'nr' => $section->order, 'title' => $section->title,
                'intro' => $section->intro, 'rows' => $rows, 'count' => count($questions),
                'flags' => count(array_filter($questions, fn ($r) => $r['flagged']))];
        }
        // Retain metadata entered through the retired custom photo screen in
        // historical reviews. It no longer defines questions or completion rules.
        $legacy = [];
        foreach ($answers['__photos'] ?? [] as $key => $value) {
            if (in_array($key, $usedMetadata, true)) {
                continue;
            }
            $legacy[] = ['id' => '__photos.'.$key, 'key' => $key, 'section' => '__photos',
                'label' => $key, 'type' => 'text', 'unit' => null, 'sub' => [], 'value' => $value,
                'empty' => $this->empty($value), 'required' => false, 'flagged' => false,
                'critical' => false, 'protocol' => [], 'flagged_options' => [], 'attachments' => []];
        }
        if ($legacy) {
            $sections[] = ['id' => '__photos', 'nr' => count($sections), 'title' => 'Eerder opgeslagen fotogegevens',
                'intro' => null, 'rows' => $legacy, 'count' => count($legacy), 'flags' => 0];
        }
        $rows = array_merge([], ...array_column($sections, 'rows'));
        $questions = array_values(array_filter($rows, fn ($r) => $r['type'] !== 'sectionhead'));

        return ['sections' => $sections, 'safety' => $safety, 'triggers' => $triggers, 'answers' => $answers,
            'counts' => ['all' => count($questions), 'flags' => count(array_filter($questions, fn ($r) => $r['flagged'])),
                'protocol' => count($triggers), 'empty' => count(array_filter($questions, fn ($r) => $r['empty']))],
            'blocked' => count(array_filter($safety, fn ($r) => $r['critical'])) > 0,
            'accepted' => array_values(array_intersect($booking->accepted_triggers ?? [], array_column($triggers, 'id'))),
            'notes' => $booking->review_notes ?? '', 'updated_at' => $booking->review_updated_at?->toISOString()];
    }

    public function sectionAnswers(IntakeSection $section, array $answers): array
    {
        $values = $answers[$section->key] ?? [];
        foreach ($section->fields as $field) {
            $source = $answers[$field->answer_section_key ?? $section->key] ?? [];
            if (array_key_exists($field->key, $source)) {
                $values[$field->key] = $source[$field->key];
            }
        }

        return $values;
    }

    public function empty(mixed $value): bool
    {
        if (is_array($value)) {
            return count(array_filter($value, fn ($v) => ! $this->empty($v))) === 0;
        }

        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function matches(mixed $value, mixed $condition): bool
    {
        if ($condition === null || $this->empty($value)) {
            return false;
        }
        $values = is_array($value) ? $value : [$value];
        $options = is_array($condition) ? $condition : [$condition];

        return count(array_intersect(array_filter($values, 'is_scalar'), $options)) > 0;
    }

    private function realOptions(mixed $value, array $none): array
    {
        return array_values(array_filter(is_array($value) ? $value : [$value], fn ($v) => is_scalar($v) && ! $this->empty($v)
            && ! in_array(mb_strtolower(trim((string) $v)), array_map(fn ($s) => mb_strtolower(trim($s)), $none), true)));
    }

    private function flagged(mixed $value, mixed $rule, array $none): bool
    {
        if ($rule === 'non-empty') {
            return ! $this->empty($value) && (! is_string($value) || ! preg_match('/^(geen|nee|n\.v\.t\.)/iu', trim($value)));
        }
        if ($rule === 'any') {
            return count($this->realOptions($value, $none)) > 0;
        }

        return $this->matches($value, $rule);
    }

    public function visible(?array $conditions, array $section, array $answers, array $none): bool
    {
        foreach ($conditions ?? [] as $key => $condition) {
            $value = str_contains($key, '.') ? data_get($answers, $key) : ($section[$key] ?? null);
            $options = is_array($condition) ? $condition : [$condition];
            if (in_array('any-checked', $options, true) || in_array('multi-checked', $options, true)) {
                if (count($this->realOptions($value, $none)) < (in_array('multi-checked', $options, true) ? 2 : 1)) {
                    return false;
                }
            } elseif (! $this->matches($value, $condition)) {
                return false;
            }
        }

        return true;
    }
}
