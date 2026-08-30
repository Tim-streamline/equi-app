<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\IntakeResponse;
use App\Models\Protocol;

class ProtocolNutrition
{
    public function answers(Horse $horse): array
    {
        $response = IntakeResponse::query()->where('horse_id', $horse->id)
            ->whereNotNull('submitted_at')->orderByDesc('submitted_at')->with('answers')->first();

        return $response?->answers->mapWithKeys(function ($answer) {
            $decoded = json_decode($answer->value, true);

            return [$answer->field_id => json_last_error() === JSON_ERROR_NONE ? $decoded : $answer->value];
        })->all() ?? [];
    }

    public function forProtocol(Protocol $protocol, array $answers): array
    {
        $settings = $protocol->customer_settings ?? [];
        // Never substitute current weight for the therapist's target weight.
        $weight = $settings['target_weight_kg'] ?? $answers['streefgewicht'] ?? null;
        $weight = is_numeric($weight) && $weight > 0 ? (float) $weight : null;
        $min = $weight === null ? null : round($weight * .02, 2);
        $max = $weight === null ? null : round($weight * .03, 2);
        $number = fn ($value) => str_replace('.', ',', (string) $value);
        $feeds = [];
        $overrides = collect($settings['feed_overrides'] ?? [])->keyBy('id');
        foreach (is_array($answers['huidige-bijvoeding'] ?? null) ? $answers['huidige-bijvoeding'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim(($row['merk'] ?? '').' '.($row['product'] ?? ''));
            if ($name === '') {
                continue;
            }
            // Identity survives reordering of the intake's repeater rows.
            $id = hash('sha256', mb_strtolower($name));
            $override = $overrides->get($id, []);
            $feeds[] = [
                'id' => $id, 'name' => $name,
                'dosage' => implode(' · ', array_filter([$row['hoeveelheid'] ?? null, empty($row['voerbeurten']) ? null : $row['voerbeurten'].' voerbeurten per dag'])),
                'status' => $override['status'] ?? $this->evaluateFeed($name),
                'note' => $override['note'] ?? null,
            ];
        }
        $water = $answers['water-type'] ?? [];
        $water = is_array($water) ? $water : [$water];
        $water = array_values(array_filter($water, 'is_string'));
        $needsAnalysis = collect($water)->contains(fn ($type) => preg_match('/grondwater|bronwater|regenwater|sloot|beek|vijver/iu', $type) === 1);

        return [
            'roughage' => [
                'targetWeightKg' => $weight, 'minimumKg' => $min, 'maximumKg' => $max,
                'rangeLabel' => $weight === null ? null : $number($min).' – '.$number($max).' kg',
                'description' => $weight === null ? 'Je therapeut heeft nog geen streefgewicht ingesteld.'
                    : '2 tot 3 kg ruwvoer per 100 kg gewenst lichaamsgewicht. Bij een streefgewicht van '.$number($weight).' kg komt dat uit op '.$number($min).' tot '.$number($max).' kg per dag. Maximaal 1 uur leegstand inclusief de nachten, maar liever geen.',
                'sugar' => $settings['sugar'] ?? '<7%', 'protein' => $settings['protein'] ?? '6–9%',
            ],
            'feeds' => $feeds,
            'water' => ['types' => $water, 'needsAnalysis' => $needsAnalysis,
                'advice' => $needsAnalysis ? 'Laat dit water analyseren. De kwaliteit en samenstelling van dit water kunnen variëren.' : null],
        ];
    }

    // Replace this policy with the future nutrition-database evaluator;
    // manual per-horse decisions stay outside and take precedence.
    protected function evaluateFeed(string $name): string
    {
        return preg_match('/metazoa|okapi/iu', $name) === 1 ? 'continue' : 'stop';
    }
}
