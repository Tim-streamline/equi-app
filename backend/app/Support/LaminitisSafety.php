<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\IntakeAnswer;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use App\Models\IntakeSafetyReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Configurable workflow. The class/storage names remain compatible with existing clients and holds. */
/** Legacy recovery logic retained only for historical migration and repair steps. */
class LaminitisSafety
{
    public function config(?Horse $horse = null): ?array
    {
        return $horse?->laminitis_safety['configuration']
            ?? IntakeQuestionnaire::where('slug', 'protocol-intake')->first()?->safety_config;
    }

    public function recoveryFields(array $config): array
    {
        $keys = array_keys($config['recovery']['require']);
        foreach ($config['recovery']['conditional'] as $rule) {
            $keys = [...$keys, ...array_keys($rule['require'])];
        }

        return IntakeField::whereIn('key', $keys)->whereHas('section.questionnaire', fn ($q) => $q->where('slug', 'protocol-intake'))
            ->get()->map(fn ($f) => ['id' => $f->key, 'label' => $f->label, 'options' => $f->options ?? []])->all();
    }

    public function answers(IntakeBooking $booking, ?array $config = null): array
    {
        $config ??= $this->config();

        return $booking->answers()->where('section_id', $config['section'] ?? '')->get()
            ->mapWithKeys(fn ($a) => [$a->field_id => json_decode($a->value ?? 'null', true)])->all();
    }

    /** Import once, preserving the complete legacy episode and its audit history. Caller locks the horse. */
    public function importLegacy(Horse $horse): void
    {
        if (IntakeSafetyReport::where('horse_id', $horse->id)->exists()) {
            return;
        }
        $state = $horse->laminitis_safety ?? [];
        if (empty($state['episode_id'])) {
            return;
        }
        $source = IntakeBooking::where('horse_id', $horse->id)->find($state['booking_id'] ?? null);
        IntakeSafetyReport::create(['horse_id' => $horse->id, 'intake_booking_id' => $source?->id,
            'state' => $this->legacyReportState($state, $source)]);
    }

    /** Pin a legacy episode without changing its identity, release, or audit history. */
    public function legacyReportState(array $state, ?IntakeBooking $source): array
    {
        $config = $state['configuration'] ?? $this->config();
        if ($config) {
            $state['configuration'] = $config;
            $state['recovery_fields'] ??= $this->recoveryFields($config);
            $rules = app(IntakeSafetySettings::class);
            $values = $source ? $this->answers($source, $config) : [];
            $requirements = $rules->requirements($config, $values);
            // Older history can predate requirement snapshots. Recover any
            // conditional requirements implied by its recorded trigger, even
            // when today's intake answers describe a different concern.
            $trigger = collect($config['triggers'])->firstWhere('id', $state['kind'] ?? null);
            foreach ($config['recovery']['conditional'] as $condition) {
                $implied = $trigger && collect($condition['when'])->every(fn ($accepted, $field) =>
                    isset($trigger['when'][$field]) && count(array_diff($trigger['when'][$field], $accepted)) === 0);
                if ($implied) {
                    $requirements = $this->mergeRequirements($requirements, $condition['require']);
                }
            }
            // The old single hold could encompass more than one trigger. Keep
            // that scope so its immutable source answers remain historical.
            $state['covered_triggers'] = array_values(array_unique(array_filter([
                ...($state['covered_triggers'] ?? []), $state['kind'] ?? null, ...array_column(array_filter($config['triggers'], fn ($t) => $rules->matches($t['when'], $values)), 'id'),
            ])));
            $state['requirements'] = $this->mergeRequirements($state['requirements'] ?? [], $requirements);
        }

        return $state;
    }

    private function mergeRequirements(array $saved, array $additional): array
    {
        foreach ($additional as $field => $accepted) {
            $saved[$field] = isset($saved[$field]) ? array_values(array_intersect($saved[$field], $accepted)) : $accepted;
        }

        return $saved;
    }

    /** Publish all reports through the existing horse sync column. Caller locks the horse. */
    public function project(Horse $horse, bool $touch = true): array
    {
        $reports = IntakeSafetyReport::where('horse_id', $horse->id)->orderByDesc('updated_at')->orderByDesc('id')->get()
            ->map(fn ($r) => $r->state + ['report_id' => $r->id])->all();
        if (! $reports) {
            return $horse->laminitis_safety ?? [];
        }
        $head = collect($reports)->first(fn ($r) => empty($r['released_at'])) ?? $reports[0];
        $projection = $head + ['version' => 2, 'reports' => $reports];
        // A revision also invalidates a release response when another report changes.
        $previous = $horse->laminitis_safety ?? [];
        $comparison = $previous;
        unset($comparison['revision']);
        if ($comparison === $projection) {
            return $previous;
        }
        $projection['revision'] = (string) Str::uuid();
        $updates = ['laminitis_safety' => json_encode($projection, JSON_THROW_ON_ERROR)];
        if ($touch) {
            $updates['updated_at'] = now();
        }
        DB::table('horses')->where('id', $horse->id)->update($updates);
        $horse->laminitis_safety = $projection;

        return $projection;
    }

    public function observe(IntakeAnswer $answer): void
    {
        // Compatibility for historical migrations; retired questionnaires no
        // longer create or renew recovery reports.
        if (! $this->config()) return;
        $booking = IntakeBooking::find($answer->response_id);
        if (! $booking || $booking->submitted_at || $booking->completed_at) {
            return;
        }
        DB::transaction(function () use ($booking, $answer): void {
            $horse = Horse::lockForUpdate()->findOrFail($booking->horse_id);
            $this->importLegacy($horse);
            $rules = app(IntakeSafetySettings::class);
            $reports = IntakeSafetyReport::where('horse_id', $horse->id)->where('intake_booking_id', $booking->id)->get();
            $configs = array_filter([$this->config()]);
            foreach ($reports as $report) {
                $state = $report->state;
                $config = $state['configuration'] ?? null;
                if (! $config) {
                    continue;
                }
                if (! in_array($config, $configs, true)) {
                    $configs[] = $config;
                }
                if ($answer->section_id !== $config['section']) {
                    continue;
                }
                $values = $this->answers($booking, $config);
                if (isset($state['requirements'][$answer->field_id]) && ! $rules->matches([$answer->field_id => $state['requirements'][$answer->field_id]], $values)) {
                    // An explicit retraction applies only to reports from this intake.
                    if (! empty($state['released_at'])) {
                        $state = $this->renew($state);
                        $state['client_marker'] = $values[$config['marker_field']] ?? null;
                        $report->update(['state' => $state]);
                    }
                }
            }
            foreach ($configs as $config) {
                if ($answer->section_id !== $config['section']) {
                    continue;
                }
                $values = $this->answers($booking, $config);
                foreach ($config['triggers'] as $trigger) {
                    if (! array_key_exists($answer->field_id, $trigger['when']) || ! $rules->matches($trigger['when'], $values)) {
                        continue;
                    }
                    // Different intakes or trigger kinds are separate concerns. A
                    // repeated report only updates its own requirement snapshot.
                    $report = $reports->first(fn ($r) => ($r->state['kind'] ?? null) === $trigger['id'] && ($r->state['configuration'] ?? null) === $config);
                    $state = $report?->state ?? [
                        'episode_id' => (string) Str::uuid(), 'kind' => $trigger['id'],
                        'reported_at' => now()->toISOString(), 'booking_id' => $booking->id,
                        'source' => 'owner_reported', 'reported_by' => $booking->user_id, 'history' => [],
                        'recovery_fields' => $this->recoveryFields($config), 'configuration' => $config,
                    ];
                    if ($report && ($trigger['renew'] || ! empty($state['released_at']))) {
                        $state = $this->renew($state);
                    }
                    $state['requirements'] = $this->mergeRequirements($state['requirements'] ?? [], $rules->requirements($config, $values));
                    $state['known_markers'] = array_values(array_unique(array_filter([
                        ...($state['known_markers'] ?? []), $state['client_marker'] ?? null, $values[$config['marker_field']] ?? null,
                    ])));
                    $state['client_marker'] = $values[$config['marker_field']] ?? null;
                    $state['trigger_values'] = array_intersect_key($values, $trigger['when']);
                    if ($report) {
                        $report->update(['state' => $state]);
                    } else {
                        $reports->push(IntakeSafetyReport::create(['horse_id' => $horse->id, 'intake_booking_id' => $booking->id, 'state' => $state]));
                    }
                }
            }
            $this->project($horse);
        });
    }

    private function renew(array $state): array
    {
        $history = $state['history'] ?? [];
        $previous = $state;
        unset($previous['history']);
        $history[] = $previous;
        unset($state['released_at'], $state['released_by'], $state['release_booking_id'], $state['release_source'], $state['confirmations']);
        $state['history'] = $history;
        $state['episode_id'] = (string) Str::uuid();
        $state['reported_at'] = now()->toISOString();

        return $state;
    }

    private function states(Horse $horse): array
    {
        $reports = IntakeSafetyReport::where('horse_id', $horse->id)->get();
        if ($reports->isNotEmpty()) {
            return $reports->pluck('state')->all();
        }
        $legacy = $horse->laminitis_safety;

        return ! empty($legacy['episode_id']) ? [$legacy] : [];
    }

    public function blocked(Horse $horse): bool
    {
        return collect($this->states($horse))->contains(fn ($s) => empty($s['released_at']));
    }

    public function withState(array $answers, Horse $horse): array
    {
        $states = $this->states($horse);
        $blocked = collect($states)->contains(fn ($s) => empty($s['released_at']));
        foreach (array_filter([$this->config(), ...array_column($states, 'configuration')]) as $config) {
            $answers[$config['section']][$config['state_field']] = $blocked ? $config['state_value'] : $config['clear_value'];
        }

        return $answers;
    }

    public function releasedCritical(Horse $horse, string $section, string $field, ?string $bookingId = null): bool
    {
        // Reviews inspect many fields on the same horse snapshot. Load its
        // report evidence once; enforcement methods still query current reports.
        $reports = $horse->loadMissing('safetyReports')->safetyReports;
        $states = $reports->isNotEmpty() ? $reports->pluck('state')->all() : [$horse->laminitis_safety ?? []];
        foreach ($states as $state) {
            $config = $state['configuration'] ?? null;
            if (! $config || $section !== $config['section'] || empty($state['released_at']) || ($bookingId && ($state['booking_id'] ?? null) !== $bookingId)) {
                continue;
            }
            $trigger = collect($config['triggers'])->firstWhere('id', $state['kind'] ?? null);
            if (in_array($field, $trigger['critical_fields'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public function message(Horse $horse): string
    {
        return $this->config($horse)['messages']['blocked'] ?? 'Er staat een veiligheidsblokkade open. Bevestig eerst de actuele herstelstatus.';
    }

    public function assertAllowed(Horse $horse, string $action = 'protocol'): void
    {
        foreach ($this->states($horse) as $state) {
            if (empty($state['released_at']) && in_array($action, $state['configuration']['actions'] ?? ['submit', 'preview', 'protocol'], true)) {
                throw ValidationException::withMessages(['laminitis' => $state['configuration']['messages']['blocked'] ?? $this->message($horse)]);
            }
        }
    }

    public function release(IntakeBooking $booking, string $episode, string $userId, ?array $declarations = null): array
    {
        return DB::transaction(function () use ($booking, $episode, $userId, $declarations): array {
            $horse = Horse::lockForUpdate()->findOrFail($booking->horse_id);
            $this->importLegacy($horse);
            $report = IntakeSafetyReport::where('horse_id', $horse->id)->get()->first(fn ($r) => ($r->state['episode_id'] ?? null) === $episode);
            $state = $report?->state ?? [];
            $config = $state['configuration'] ?? null;
            if (! $report || ! $config) {
                throw ValidationException::withMessages(['laminitis' => 'De herstelstatus is gewijzigd. Vernieuw de intake en bevestig de actuele status.']);
            }
            $rules = app(IntakeSafetySettings::class);
            $requirements = $state['requirements'] ?? $rules->requirements($config, []);
            $source = $report->intake_booking_id ? IntakeBooking::where('horse_id', $horse->id)->find($report->intake_booking_id) : null;
            $context = array_replace($state['trigger_values'] ?? [], $source ? $this->answers($source, $config) : []);
            $answers = $this->answers($booking, $config);
            // New clients always confirm this report separately. Retain the draft
            // answer path for installed clients; neither path rewrites answers.
            if ($declarations !== null || $booking->submitted_at || $booking->completed_at) {
                if (array_diff(array_keys($declarations ?? []), array_keys($requirements))) {
                    throw ValidationException::withMessages(['laminitis' => 'Onbekende herstelverklaring.']);
                }
                $answers = $declarations ?? [];
            }
            // Confirmations override recovery fields only. Another intake, or
            // an omitted declaration, cannot erase the source trigger context.
            // Other concerns have independent reports and keep the horse blocked.
            $context = array_replace($context, array_intersect_key($answers, $requirements));
            $blocking = array_filter($config['triggers'], function ($trigger) use ($rules, $context, $state): bool {
                if (! $trigger['block_while_matching'] || ! in_array($trigger['id'], [$state['kind'] ?? null, ...($state['covered_triggers'] ?? [])], true)) {
                    return false;
                }
                if ($rules->matches($trigger['when'], $context)) {
                    return true;
                }
                // Missing legacy/deleted source answers cannot prove a recorded
                // blocking condition has ended. Require evidence contradicting it.
                foreach ($trigger['when'] as $field => $accepted) {
                    if (isset($context[$field]) && $context[$field] !== '' && ! in_array($context[$field], $accepted, true)) {
                        return false;
                    }
                }

                return true;
            });
            if ($blocking || ! $rules->matches($requirements, $answers)) {
                throw ValidationException::withMessages(['laminitis' => $blocking ? $config['messages']['blocked'] : 'Beantwoord alle herstelvragen met een geconfigureerd bevestigend antwoord.']);
            }
            if (empty($state['released_at'])) {
                $state += ['released_at' => now()->toISOString(), 'released_by' => $userId,
                    'release_booking_id' => $booking->id, 'release_source' => $config['release_source'],
                    'confirmations' => array_intersect_key($answers, $requirements)];
                $report->update(['state' => $state]);
            }

            return $this->project($horse);
        });
    }
}
