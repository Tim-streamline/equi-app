<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\IntakeAnswer;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use Illuminate\Support\Facades\DB;

/** Clear removed choices, without treating missing answers as recovery evidence. */
class IntakeObsoleteAnswers
{
    /** Acknowledge stale offline choices without restoring a removed answer. */
    public function discardObsoleteAnswer(?IntakeAnswer $answer): void
    {
        if (! $answer) {
            return;
        }
        $field = IntakeField::with('section')->where('key', $answer->field_id)->where('active', true)
            ->whereHas('section', fn ($q) => $q->where('active', true)->whereHas('questionnaire', fn ($q) => $q->where('slug', 'protocol-intake')))
            ->get()->first(fn ($f) => ($f->answer_section_key ?? $f->section->key) === $answer->section_id);
        if ($field && $this->obsolete($field, json_decode($answer->value ?? 'null', true))) {
            // A removed option must be answered again using the current questionnaire.
            DB::table('intake_answers')->where('id', $answer->id)->delete();
        }
    }

    public function obsolete(IntakeField $field, mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return false;
        }
        // Match questionnaire rendering without rewriting historical answer values.
        $alias = fn ($choice) => is_string($choice) ? ($field->answer_aliases[$choice] ?? $choice) : $choice;
        $value = is_array($value) ? array_map($alias, $value) : $alias($value);
        $options = $field->options ?? [];

        return match ($field->type) {
            'radio' => ! is_string($value) || ! in_array($value, $options, true),
            'multi' => ! is_array($value) || collect($value)->contains(fn ($choice) => ! is_string($choice) || ! in_array($choice, $options, true)),
            'number' => ! $field->age_config && is_string($value) && ! is_numeric($value) && ! in_array($value, $options, true),
            default => false,
        };
    }

    public function run(): void
    {
        $questionnaire = IntakeQuestionnaire::where('slug', 'protocol-intake')->first();
        if (! $questionnaire) {
            return;
        }
        $fields = IntakeField::with('section')->where('active', true)
            ->whereHas('section', fn ($q) => $q->where('questionnaire_id', $questionnaire->id)->where('active', true))->get();
        // Capture all historical concerns BEFORE their source answers disappear.
        app(IntakeSafetyReportBackfill::class)->run();
        Horse::orderBy('id')->chunkById(100, function ($horses) use ($fields, $questionnaire): void {
            foreach ($horses as $horse) {
                DB::transaction(function () use ($horse, $fields, $questionnaire): void {
                    $horse = Horse::lockForUpdate()->findOrFail($horse->id);
                    $safety = app(LaminitisSafety::class);
                    $obsolete = [];
                    foreach (IntakeBooking::where('horse_id', $horse->id)->lockForUpdate()->get() as $booking) {
                        foreach ($booking->answers()->get() as $answer) {
                            $field = $fields->first(fn ($f) => $f->key === $answer->field_id && ($f->answer_section_key ?? $f->section->key) === $answer->section_id);
                            if ($field && $this->obsolete($field, json_decode($answer->value ?? 'null', true))) {
                                $obsolete[$booking->id][$answer->section_id][$answer->field_id] = $answer;
                            }
                        }
                    }
                    foreach ($horse->safetyReports()->get() as $report) {
                        $state = $report->state;
                        if (! empty($state['released_at'])) {
                            continue; // Completed recovery evidence stays historical.
                        }
                        $config = $state['configuration'] ?? null;
                        if (! $config) {
                            continue;
                        }
                        $values = array_map(fn ($a) => json_decode($a->value ?? 'null', true), $obsolete[$report->intake_booking_id][$config['section']] ?? []);
                        $triggerKeys = [];
                        foreach ($config['triggers'] as $trigger) {
                            if (in_array($trigger['id'], [$state['kind'] ?? null, ...($state['covered_triggers'] ?? [])], true)) {
                                $triggerKeys += $trigger['when'];
                            }
                        }
                        // Deleted source values must not make a blocking trigger disappear.
                        if ($removedTriggers = array_intersect_key($values, $triggerKeys)) {
                            $state['trigger_values'] = array_replace($state['trigger_values'] ?? [], $removedTriggers);
                        }
                        foreach ($state['requirements'] ?? [] as $key => $accepted) {
                            $field = $fields->first(fn ($f) => $f->key === $key && ($f->answer_section_key ?? $f->section->key) === $config['section']);
                            if (! $field || ($accepted && ! array_diff($accepted, $field->options ?? []))) {
                                continue;
                            }
                            $replacement = array_values(array_intersect($accepted, $field->options ?? []));
                            if (! $replacement && ($questionnaire->safety_config['section'] ?? null) === $config['section']) {
                                $recovery = $questionnaire->safety_config['recovery'];
                                $candidates = array_values(array_filter([
                                    $recovery['require'][$key] ?? null,
                                    ...array_map(fn ($rule) => $rule['require'][$key] ?? null, $recovery['conditional']),
                                ]));
                                // Never guess a positive value, or weaken conflicting rules.
                                if ($candidates) {
                                    $replacement = array_values(array_intersect($field->options ?? [], ...$candidates));
                                }
                            }
                            if (! $replacement) {
                                continue; // A missing replacement requires configuration; retain the hold.
                            }
                            $state['requirements'][$key] = $replacement;
                            if (isset($state['configuration']['recovery']['require'][$key])) {
                                $state['configuration']['recovery']['require'][$key] = $replacement;
                            }
                            foreach ($state['configuration']['recovery']['conditional'] as &$rule) {
                                if (isset($rule['require'][$key])) {
                                    $rule['require'][$key] = $replacement;
                                }
                            }
                            unset($rule, $state['confirmations'][$key]);
                            $snapshot = ['id' => $key, 'label' => $field->label, 'options' => $field->options];
                            $state['recovery_fields'] = collect($state['recovery_fields'] ?? [])->reject(fn ($f) => $f['id'] === $key)->push($snapshot)->values()->all();
                        }
                        if ($state !== $report->state) {
                            $report->update(['state' => $state]);
                        }
                    }
                    foreach ($obsolete as $bookingId => $sections) {
                        foreach ($sections as $answers) {
                            foreach ($answers as $answer) {
                                // No observers: deletion cannot release or renew a safety report.
                                DB::table('intake_answers')->where('id', $answer->id)->delete();
                            }
                        }
                        // Reopen only intakes that actually lost an answer. Keep
                        // their protocol, attachments, review and delivery history.
                        DB::table('intake_bookings')->where('id', $bookingId)
                            ->where(fn ($q) => $q->whereNotNull('submitted_at')->orWhereNotNull('completed_at'))
                            ->update(['submitted_at' => null, 'completed_at' => null, 'intake_status' => 'draft', 'updated_at' => now()]);
                    }
                    $safety->project($horse, touch: false);
                });
            }
        });
    }
}
