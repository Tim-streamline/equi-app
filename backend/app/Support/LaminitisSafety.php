<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\IntakeAnswer;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Server-owned horse history, separate from editable intake answers. */
class LaminitisSafety
{
    public const RECOVERY = ['hoefbevangenheid-acute-fase-voorbij', 'hoefbevangenheid-stabiel'];
    public const ACUTE = ['nu acuut', 'Ja, mijn paard is momenteel hoefbevangen'];
    public const PAST = ['in verleden gehad', 'Ja, mijn paard is in het verleden hoefbevangen geweest'];

    private function configured(string $key): ?IntakeField
    {
        return IntakeField::where('key', $key)->whereHas('section.questionnaire', fn ($q) => $q->where('slug', 'protocol-intake'))->first();
    }

    private function acuteValues(): array
    {
        $values = $this->configured('hoefbevangenheid')?->critical_if ?? [];
        return array_unique([...self::ACUTE, ...(is_array($values) ? $values : [$values])]);
    }

    private function pastValues(): array
    {
        $values = $this->configured('hoefbevangenheid-hersteld')?->show_if['hoefbevangenheid'] ?? [];
        return array_unique([...self::PAST, ...(is_array($values) ? $values : [$values])]);
    }

    public function observe(IntakeAnswer $answer): void
    {
        if ($answer->section_id !== 'medisch' || ! in_array($answer->field_id, ['hoefbevangenheid', 'hoefbevangenheid-hersteld', ...self::RECOVERY])) {
            return;
        }
        $booking = IntakeBooking::find($answer->response_id);
        if (! $booking) {
            return;
        }
        DB::transaction(function () use ($booking, $answer): void {
            $horse = Horse::lockForUpdate()->findOrFail($booking->horse_id);
            $value = json_decode($answer->value ?? 'null', true);
            if (in_array($answer->field_id, self::RECOVERY)) {
                $state = $horse->laminitis_safety ?? [];
                if ($state && $value !== 'Ja') {
                    unset($state['released_at'], $state['released_by'], $state['release_booking_id'], $state['release_source']);
                    $horse->forceFill(['laminitis_safety' => $state])->save();
                }
                return;
            }
            $answers = $this->answers($booking);
            $acute = $answer->field_id === 'hoefbevangenheid' && in_array($value, $this->acuteValues(), true);
            $unresolved = in_array($answers['hoefbevangenheid'] ?? null, $this->pastValues(), true)
                && in_array($answers['hoefbevangenheid-hersteld'] ?? null, ['Nee', 'Weet ik niet'], true);
            if ($acute || $unresolved) {
                $state = $horse->laminitis_safety ?? [];
                if (! $acute && ! empty($state['episode_id']) && empty($state['released_at'])) {
                    return;
                }
                $history = $state['history'] ?? [];
                if ($state) {
                    unset($state['history']);
                    $history[] = $state;
                }
                $horse->forceFill(['laminitis_safety' => [
                    'episode_id' => (string) Str::uuid(), 'kind' => $acute ? 'acute' : 'unresolved',
                    'reported_at' => now()->toISOString(), 'booking_id' => $booking->id,
                    'source' => 'owner_reported', 'reported_by' => $booking->user_id, 'history' => $history,
                    'client_marker' => $answers['_laminitis_hold'] ?? null,
                ]])->save();
            }
        });
    }

    public function answers(IntakeBooking $booking): array
    {
        return $booking->answers()->where('section_id', 'medisch')->get()
            ->mapWithKeys(fn ($a) => [$a->field_id => json_decode($a->value ?? 'null', true)])->all();
    }

    public function blocked(Horse $horse): bool
    {
        $state = $horse->laminitis_safety ?? [];
        return ! empty($state['episode_id']) && empty($state['released_at']);
    }

    public function message(Horse $horse): string
    {
        $key = ($horse->laminitis_safety['kind'] ?? '') === 'unresolved'
            ? 'hoefbevangenheid-aanhoudend' : 'hoefbevangenheid-waarschuwing';
        return IntakeField::where('key', $key)->whereHas('section.questionnaire', fn ($q) => $q->where('slug', 'protocol-intake'))->value('hint')
            ?: 'Versturen en het opstellen van een Plus-protocol zijn geblokkeerd. Werk eerst de herstelstatus bij.';
    }

    public function assertAllowed(Horse $horse): void
    {
        if ($this->blocked($horse)) {
            throw ValidationException::withMessages(['laminitis' => $this->message($horse)]);
        }
    }

    public function release(IntakeBooking $booking, string $episode, string $userId, ?array $declarations = null): array
    {
        return DB::transaction(function () use ($booking, $episode, $userId, $declarations): array {
            $horse = Horse::lockForUpdate()->findOrFail($booking->horse_id);
            $state = $horse->laminitis_safety ?? [];
            if (($state['episode_id'] ?? null) !== $episode) {
                throw ValidationException::withMessages(['laminitis' => 'De herstelstatus is gewijzigd. Vernieuw de intake en bevestig de actuele status.']);
            }
            $answers = $this->answers($booking);
            // A submitted intake remains immutable; later recovery is a separate owner declaration.
            if ($booking->submitted_at || $booking->completed_at) {
                $answers = array_replace($answers, $declarations ?? []);
                foreach (self::RECOVERY as $key) {
                    if (($declarations[$key] ?? null) !== 'Ja') {
                        throw ValidationException::withMessages(['laminitis' => 'Bevestig de actuele herstelstatus met beide antwoorden op Ja.']);
                    }
                }
            }
            foreach (self::RECOVERY as $key) {
                if (($answers[$key] ?? null) !== 'Ja') {
                    throw ValidationException::withMessages(['laminitis' => 'Beantwoord beide herstelvragen met Ja voordat je de herstelstatus bevestigt.']);
                }
            }
            if (in_array($answers['hoefbevangenheid'] ?? null, $this->pastValues(), true) && ($answers['hoefbevangenheid-hersteld'] ?? null) !== 'Ja') {
                throw ValidationException::withMessages(['laminitis' => 'Werk ook het antwoord over volledig herstel bij. Bij aanhoudende of onduidelijke klachten blijft de blokkade gelden.']);
            }
            if (empty($state['released_at'])) {
                $state += ['released_at' => now()->toISOString(), 'released_by' => $userId,
                    'release_booking_id' => $booking->id, 'release_source' => 'owner_reported_veterinary_confirmation',
                    'confirmations' => array_intersect_key($answers, array_flip([...self::RECOVERY, 'hoefbevangenheid-hersteld']))];
                $horse->forceFill(['laminitis_safety' => $state])->save();
            }
            return $state;
        });
    }
}
