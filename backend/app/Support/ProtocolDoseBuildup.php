<?php

namespace App\Support;

use App\Models\DoseBuildup;
use App\Models\ProtocolPhaseSupplement;
use App\Models\Supplement;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Buildup belongs to a protocol selection, and advances once per recorded date. */
class ProtocolDoseBuildup
{
    public function settings(Supplement|ProtocolPhaseSupplement $source, array $data = []): ?array
    {
        $snapshot = $source instanceof Supplement ? $source->doseBuildup?->snapshot() : $source->dose_buildup;
        if (isset($data['dose_buildup_id']) && $data['dose_buildup_id'] !== ($snapshot['id'] ?? DoseBuildup::NONE_ID)) {
            return DoseBuildup::findOrFail($data['dose_buildup_id'])->snapshot();
        }

        return $snapshot;
    }

    public function enabled(?array $settings): bool
    {
        return $settings && (($settings['type'] ?? 'linear') === 'custom'
            ? collect($settings['schedule'] ?? [])->contains(fn ($step) => $step['dose_ratio'] < 1)
            : ($settings['days'] ?? 1) > 1 && ($settings['start_dose_ratio'] ?? 1) < 1);
    }

    /** Structured manual input is required for buildup; never guess from legacy free text. */
    public function attributes(Supplement|ProtocolPhaseSupplement $source, array $data, ?float $weight, string $errorKey = 'dosage'): array
    {
        $settings = $this->settings($source, $data);
        $automatic = ($data['dosage_mode'] ?? 'manual') === 'automatic';
        $dosage = $automatic ? app(ProtocolDosage::class)->calculate($source, $weight) : ($data['dosage'] ?? null);
        $amount = null;
        $unit = null;
        if ($automatic && $dosage !== null) {
            [$amount, $unit] = explode(' ', $dosage, 2);
            $amount = (float) $amount;
        } elseif (isset($data['full_dose_amount'], $data['full_dose_unit'])) {
            $amount = (float) $data['full_dose_amount'];
            $unit = $data['full_dose_unit'];
            $dosage = $this->format($amount, $unit);
        } elseif ($source instanceof ProtocolPhaseSupplement && $source->full_dose_amount !== null
            && $dosage === $source->dosage) {
            $amount = $source->full_dose_amount;
            $unit = $source->full_dose_unit;
        }
        if ($this->enabled($settings) && ($amount === null || $amount <= 0 || ! $unit)) {
            throw ValidationException::withMessages([$errorKey => 'Vul een numerieke volledige dosis en eenheid in voordat je dosisopbouw gebruikt.']);
        }

        return ['dose_buildup' => $settings, 'full_dose_amount' => $amount, 'full_dose_unit' => $unit, 'dosage' => $dosage];
    }

    public function ratio(?array $settings, int $priorDays): float
    {
        if (! $this->enabled($settings)) {
            return 1;
        }
        $days = max(1, (int) $settings['days']);
        $step = min(max(0, $priorDays), $days - 1);
        if ($settings['type'] === 'custom') {
            return (float) $settings['schedule'][$step]['dose_ratio'];
        }

        return (float) $settings['start_dose_ratio'] + (1 - $settings['start_dose_ratio']) * $step / max(1, $days - 1);
    }

    public function format(float $amount, string $unit): string
    {
        return rtrim(rtrim(number_format($amount, 10, '.', ''), '0'), '.').' '.$unit;
    }

    public function dose(ProtocolPhaseSupplement $item, int $priorDays): ?string
    {
        if (! $this->enabled($item->dose_buildup)) {
            return $item->dosage;
        }
        $amount = $item->full_dose_amount * $this->ratio($item->dose_buildup, $priorDays);
        $unit = $item->full_dose_unit;
        if ($unit === 'g') {
            $step = $amount < 10 ? 2.5 : 5;
            $amount = ceil(round($amount / $step, 12)) * $step;
        } elseif (in_array($unit, ['druppels', 'capsules', 'pillen'], true)) {
            $amount = round($amount, 0, PHP_ROUND_HALF_UP);
        }

        return $this->format($amount, $unit);
    }

    public function forDay(ProtocolPhaseSupplement $item, string $date, ?Collection $intakes = null): ?string
    {
        $intakes ??= $item->exists || $item->relationLoaded('intakes') ? $item->intakes : collect();
        // The first recorded dose fixes that day's amount, even after late check-offs
        // or edits to the full dose. Unchecking does not remove a recorded date.
        $sameDay = $intakes->filter(fn ($row) => $row->date->toDateString() === $date)
            ->sortBy(fn ($row) => ($row->created_at?->format('Y-m-d H:i:s.u') ?? '').$row->id)->first();
        if ($sameDay) {
            return $sameDay->dosage;
        }
        $priorDays = $intakes->filter(fn ($row) => $row->date->toDateString() < $date)
            ->map(fn ($row) => $row->date->toDateString())->unique()->count();

        return $this->dose($item, $priorDays);
    }

    public function label(?array $settings): ?string
    {
        if (! $this->enabled($settings)) {
            return null;
        }
        if ($settings['type'] === 'custom') {
            $steps = array_map(fn ($step) => 'dag '.$step['day'].': '.round($step['dose_ratio'] * 100, 6).'%', $settings['schedule']);

            return 'Dosisopbouw: '.implode(', ', $steps).'. Daarna 100%. Alleen geregistreerde dagen tellen mee.';
        }

        return 'Dosisopbouw: van '.round($settings['start_dose_ratio'] * 100, 6).'% naar 100% in '.$settings['days'].' geregistreerde dagen.';
    }
}
