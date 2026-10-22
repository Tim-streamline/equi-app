<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\IntakeAnswer;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Configurable workflow. The class/storage names remain compatible with existing clients and holds. */
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

    public function observe(IntakeAnswer $answer): void
    {
        $booking = IntakeBooking::find($answer->response_id);
        if (! $booking) {
            return;
        }
        DB::transaction(function () use ($booking, $answer): void {
            $horse = Horse::lockForUpdate()->findOrFail($booking->horse_id);
            $state = $horse->laminitis_safety ?? [];
            $rules = app(IntakeSafetySettings::class);
            $current = $this->config();
            $saved = $this->config($horse);
            if ($saved && $answer->section_id === $saved['section'] && $state) {
                $answers = $this->answers($booking, $saved);
                $requirements = array_replace($rules->requirements($saved, $answers), $state['requirements'] ?? []);
                if (isset($requirements[$answer->field_id]) && ! $rules->matches([$answer->field_id => $requirements[$answer->field_id]], $answers)) {
                    unset($state['released_at'], $state['released_by'], $state['release_booking_id'], $state['release_source'], $state['confirmations']);
                    $horse->forceFill(['laminitis_safety' => $state])->save();
                }
            }
            // Keep recognizing the rules of an existing episode as well as newly configured triggers.
            foreach (array_filter([$current, $saved !== $current ? $saved : null]) as $config) {
                if ($answer->section_id !== $config['section']) {
                    continue;
                }
                $answers = $this->answers($booking, $config);
                foreach ($config['triggers'] as $trigger) {
                    if (! array_key_exists($answer->field_id, $trigger['when']) || ! $rules->matches($trigger['when'], $answers)) {
                        continue;
                    }
                    if (! $trigger['renew'] && ! empty($state['episode_id']) && empty($state['released_at'])) {
                        // A second offline report may have a newer marker but belongs to the same open episode.
                        if (isset($answers[$config['marker_field']])) {
                            $state['client_marker'] = $answers[$config['marker_field']];
                            $horse->forceFill(['laminitis_safety' => $state])->save();
                        }

                        return;
                    }
                    $history = $state['history'] ?? [];
                    if ($state) {
                        unset($state['history']);
                        $history[] = $state;
                    }
                    $horse->forceFill(['laminitis_safety' => [
                        'episode_id' => (string) Str::uuid(), 'kind' => $trigger['id'],
                        'reported_at' => now()->toISOString(), 'booking_id' => $booking->id,
                        'source' => 'owner_reported', 'reported_by' => $booking->user_id, 'history' => $history,
                        'client_marker' => $answers[$config['marker_field']] ?? null,
                        'recovery_fields' => $this->recoveryFields($config), 'configuration' => $config, 'requirements' => $rules->requirements($config, $answers),
                    ]])->save();

                    return;
                }
            }
        });
    }

    public function blocked(Horse $horse): bool
    {
        $state = $horse->laminitis_safety ?? [];

        return ! empty($state['episode_id']) && empty($state['released_at']);
    }

    public function withState(array $answers, Horse $horse): array
    {
        if ($config = $this->config($horse)) {
            $answers[$config['section']][$config['state_field']] = $this->blocked($horse) ? $config['state_value'] : $config['clear_value'];
        }

        return $answers;
    }

    public function releasedCritical(Horse $horse, string $section, string $field): bool
    {
        $config = $this->config($horse);
        if (! $config || $section !== $config['section'] || empty($horse->laminitis_safety['released_at'])) {
            return false;
        }
        $trigger = collect($config['triggers'])->firstWhere('id', $horse->laminitis_safety['kind'] ?? null);

        return in_array($field, $trigger['critical_fields'] ?? [], true);
    }

    public function message(Horse $horse): string
    {
        return $this->config($horse)['messages']['blocked'] ?? 'Er staat een veiligheidsblokkade open. Bevestig eerst de actuele herstelstatus.';
    }

    public function assertAllowed(Horse $horse, string $action = 'protocol'): void
    {
        if ($this->blocked($horse) && in_array($action, $this->config($horse)['actions'] ?? ['submit', 'preview', 'protocol'], true)) {
            throw ValidationException::withMessages(['laminitis' => $this->message($horse)]);
        }
    }

    public function release(IntakeBooking $booking, string $episode, string $userId, ?array $declarations = null): array
    {
        return DB::transaction(function () use ($booking, $episode, $userId, $declarations): array {
            $horse = Horse::lockForUpdate()->findOrFail($booking->horse_id);
            $state = $horse->laminitis_safety ?? [];
            $config = $this->config($horse);
            if (($state['episode_id'] ?? null) !== $episode || ! $config) {
                throw ValidationException::withMessages(['laminitis' => 'De herstelstatus is gewijzigd. Vernieuw de intake en bevestig de actuele status.']);
            }
            $answers = $this->answers($booking, $config);
            $rules = app(IntakeSafetySettings::class);
            $requirements = array_replace($rules->requirements($config, $answers), $state['requirements'] ?? []);
            // Include requirements from the original report, even when releasing from a different intake.
            $source = IntakeBooking::find($state['booking_id'] ?? null);
            if ($source && ! isset($state['requirements'])) {
                $requirements = array_replace($requirements, $rules->requirements($config, $this->answers($source, $config)));
            }
            if ($booking->submitted_at || $booking->completed_at) {
                if (array_diff(array_keys($declarations ?? []), array_keys($requirements))) {
                    throw ValidationException::withMessages(['laminitis' => 'Onbekende herstelverklaring.']);
                }
                $answers = $declarations ?? [];
            }
            $blocking = array_filter($config['triggers'], fn ($t) => $t['block_while_matching'] && $rules->matches($t['when'], $answers));
            if ($blocking || ! $rules->matches($requirements, $answers)) {
                throw ValidationException::withMessages(['laminitis' => 'Beantwoord alle herstelvragen met een geconfigureerd bevestigend antwoord.']);
            }
            if (empty($state['released_at'])) {
                $state += ['released_at' => now()->toISOString(), 'released_by' => $userId,
                    'release_booking_id' => $booking->id, 'release_source' => $config['release_source'],
                    'confirmations' => array_intersect_key($answers, $requirements), 'configuration' => $config];
                $horse->forceFill(['laminitis_safety' => $state])->save();
            }

            return $state;
        });
    }
}
