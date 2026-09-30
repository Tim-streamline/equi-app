<?php

namespace App\Support;

use App\Enums\SupplementDoseType;
use App\Enums\SupplementDoseUnit;
use App\Models\ProtocolPhaseSupplement;
use App\Models\Supplement;

class ProtocolDosage
{
    public function calculate(Supplement|ProtocolPhaseSupplement $supplement, ?float $weight): ?string
    {
        if ($supplement->dosis === null || $supplement->dosis_type === null || $supplement->unit === null) {
            return null;
        }
        $dose = $supplement->dosis;
        $weighted = in_array($supplement->dosis_type, [SupplementDoseType::PerKilogram, SupplementDoseType::Per600Kilograms], true);
        if ($weighted) {
            if ($weight === null || $weight <= 0) {
                return null;
            }
            $dose *= $weight / ($supplement->dosis_type === SupplementDoseType::Per600Kilograms ? 600 : 1);
            if ($supplement->unit === SupplementDoseUnit::Gram) {
                $step = $dose < 10 ? 2.5 : 5;
                $dose = ceil(round($dose / $step, 12)) * $step;
            }
        }

        return rtrim(rtrim(number_format($dose, 12, '.', ''), '0'), '.').' '.$supplement->unit->value;
    }
}
