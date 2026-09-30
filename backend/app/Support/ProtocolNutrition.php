<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\Protocol;

class ProtocolNutrition
{
    public function answers(Horse $horse): array
    {
        $response = IntakeBooking::query()->where('horse_id', $horse->id)
            ->whereNotNull('submitted_at')->orderByDesc('submitted_at')->with('answers')->first();

        return $response?->answers->mapWithKeys(function ($answer) {
            $decoded = json_decode($answer->value, true);

            return [$answer->field_id => json_last_error() === JSON_ERROR_NONE ? $decoded : $answer->value];
        })->all() ?? [];
    }

    public function forProtocol(Protocol $protocol, array $answers): array
    {
        $settings = $protocol->customer_settings ?? [];
        $actual = $this->actualWeight($protocol, $answers);
        // Old protocols with a target retain it; new editors explicitly opt in/out.
        $useTarget = $settings['use_target_weight'] ?? filled($settings['target_weight_kg'] ?? $answers['streefgewicht'] ?? null);
        $weight = $useTarget ? $this->weight($settings['target_weight_kg'] ?? $answers['streefgewicht'] ?? null) : $actual;
        $min = $weight === null ? null : round($weight * .02, 2);
        $max = $weight === null ? null : round($weight * .03, 2);
        $number = fn ($value) => str_replace('.', ',', (string) $value);
        $overrides = collect($settings['feed_overrides'] ?? [])->keyBy('id');
        $feeds = array_map(function ($feed) use ($overrides) {
            $override = $overrides->get($feed['id'], []);

            return $feed + ['status' => $override['status'] ?? $this->evaluateFeed($feed['name']), 'note' => $override['note'] ?? null];
        }, $this->currentFeeds($answers));
        $water = $answers['water-type'] ?? [];
        $water = is_array($water) ? $water : [$water];
        $water = array_values(array_filter($water, 'is_string'));
        $needsAnalysis = collect($water)->contains(fn ($type) => preg_match('/grondwater|bronwater|regenwater|sloot|beek|vijver/iu', $type) === 1);

        return [
            'roughage' => [
                'actualWeightKg' => $actual, 'usesTargetWeight' => (bool) $useTarget, 'targetWeightKg' => $useTarget ? $weight : null, 'weightKg' => $weight, 'minimumKg' => $min, 'maximumKg' => $max,
                'rangeLabel' => $weight === null ? null : $number($min).' – '.$number($max).' kg',
                'description' => $weight === null ? 'Je therapeut heeft nog geen gewicht ingesteld.'
                    : '2 tot 3 kg ruwvoer per 100 kg lichaamsgewicht. Bij een '.($useTarget ? 'streefgewicht' : 'gewicht').' van '.$number($weight).' kg komt dat uit op '.$number($min).' tot '.$number($max).' kg per dag. Maximaal 1 uur leegstand inclusief de nachten, maar liever geen.',
                'sugar' => $settings['sugar'] ?? '<7%', 'protein' => $settings['protein'] ?? '6–9%',
            ],
            'feeds' => $feeds,
            'water' => ['types' => $water, 'needsAnalysis' => $needsAnalysis,
                'advice' => $needsAnalysis ? 'Laat dit water analyseren. De kwaliteit en samenstelling van dit water kunnen variëren.' : null],
        ];
    }

    public function weight(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        return is_numeric($value) && $value > 0 && $value <= 2000 ? (float) $value : null;
    }

    public function actualWeight(Protocol $protocol, array $answers): ?float
    {
        if (array_key_exists('weight_kg', $protocol->customer_settings ?? [])) {
            return $this->weight($protocol->customer_settings['weight_kg']);
        }

        return $this->weight($answers['gewicht'] ?? null)
            ?? $this->weight($protocol->horse?->weight_kg);
    }

    /** Only current-product answers: history repeaters are deliberately excluded. */
    public function currentFeeds(array $answers): array
    {
        $products = [];
        foreach (['huidige-bijvoeding' => 'bijvoeding-nu', 'balancer' => 'balancer-nu', 'huidig-extra' => 'supplementen-nu', 'snacks-details' => 'snacks-aanwezig'] as $field => $gate) {
            if (isset($answers[$gate]) && mb_strtolower(trim((string) $answers[$gate])) !== 'ja') {
                continue;
            }
            $rows = $answers[$field] ?? [];
            if (is_string($rows) && trim($rows) !== '') {
                $rows = [['product' => $rows]];
            }
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $text = fn ($key) => is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';
                $name = trim(implode(' ', array_filter([$text('merk'), $text('product'), $text('wat')])));
                if ($name === '') {
                    continue;
                }
                $amount = $text('hoeveelheid') ?: $text('dosering') ?: $text('hoeveel');
                $frequency = $text('voerbeurten') !== '' ? $text('voerbeurten').' voerbeurten per dag'
                    : ($text('hoevaak') ?: ($text('frequentie') ?: ($field === 'huidig-extra' && $amount !== '' ? 'per dag' : '')));
                $products[] = ['source' => $field, 'name' => $name, 'dosage' => implode(' · ', array_filter([$amount, $frequency]))];
            }
        }
        $minerals = $answers['mineralen-toegang'] ?? [];
        foreach (is_array($minerals) ? $minerals : [$minerals] as $mineral) {
            if (! is_string($mineral) || in_array(mb_strtolower(trim($mineral)), ['', 'geen', 'nee', 'weet ik niet'], true)) {
                continue;
            }
            if (mb_strtolower(trim($mineral)) === 'anders') {
                $mineral = is_string($answers['mineralen-toegang-anders'] ?? null) ? trim($answers['mineralen-toegang-anders']) : '';
            }
            if ($mineral !== '') {
                $products[] = ['source' => 'mineralen-toegang', 'name' => $mineral, 'dosage' => ''];
            }
        }
        $counts = array_count_values(array_map(fn ($p) => $p['source'].'|'.mb_strtolower($p['name']), $products));
        $occurrences = [];

        return array_map(function ($product) use ($counts, &$occurrences) {
            $key = $product['source'].'|'.mb_strtolower($product['name']);
            // Keep existing bijvoeding IDs; scope new categories and distinguish repeat entries.
            $identity = $product['source'] === 'huidige-bijvoeding' ? mb_strtolower($product['name']) : $key;
            if ($counts[$key] > 1) {
                $identity .= '|'.$product['dosage'];
                $occurrences[$identity] = ($occurrences[$identity] ?? 0) + 1;
                $identity .= '|'.$occurrences[$identity];
            }

            return ['id' => hash('sha256', $identity), 'name' => $product['name'], 'dosage' => $product['dosage']];
        }, $products);
    }

    // Replace this policy with the future nutrition-database evaluator;
    // manual per-horse decisions stay outside and take precedence.
    protected function evaluateFeed(string $name): string
    {
        return preg_match('/metazoa|okapi/iu', $name) === 1 ? 'continue' : 'stop';
    }
}
