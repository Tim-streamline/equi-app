<?php

namespace App\Support;

use App\Models\Protocol;

/** Quantities to purchase, independent of phase visibility and calendar start date. */
class ProtocolOrderList
{
    public const DISCOUNT = '5% korting bij Dtails.nl met code: paardentherapeut-5';

    public function forProtocol(Protocol $protocol): array
    {
        $protocol->loadMissing(['phases.supplements.weeks', 'phases.supplements.supplement']);
        $items = [];
        foreach ($protocol->phases as $phase) {
            foreach ($phase->supplements as $selection) {
                $weeks = $selection->weeks->pluck('protocol_phase_week_id')->unique()->count();
                if (! $weeks) {
                    continue;
                }
                $dose = $this->parseDose($selection->dosage);
                // Ordering always budgets daily use. The configured weekly number
                // is a minimum adherence target, not the quantity to purchase.
                $perDay = max(1, (int) ($selection->supplement?->administrations_per_day ?? $selection->administrations_per_day ?? 1));
                $frequency = 7 * $perDay;
                $known = $dose !== null;
                // Links are deliberately live catalogue data; changing a webshop URL
                // must also update an already published protocol's order list.
                $url = $selection->supplement?->product_url;
                $url = $this->safeUrl($url);
                $unit = $dose['unit'] ?? $this->parseDose('1 '.($selection->unit?->value ?? ''))['unit'] ?? null;
                $identity = mb_strtolower(trim($selection->name)).'|'.$unit;
                $key = hash('sha256', $identity);
                $items[$key] ??= [
                    'id' => $key, 'productKey' => $key, 'name' => $selection->name,
                    'catalogIds' => [], 'selectionIds' => [], 'quantity' => 0, 'unit' => $unit,
                    'quantityLabel' => null, 'calculationComplete' => true,
                    'purchaseOptions' => [],
                    'components' => [],
                    'calculationBasis' => 'daily_7_per_week',
                ];
                $item = &$items[$key];
                if ($url && ! in_array($url, array_column($item['purchaseOptions'], 'url'), true)) {
                    $item['purchaseOptions'][] = ['id' => hash('sha256', $url), 'catalogId' => $selection->supplement_id,
                        'url' => $url, 'label' => 'Bekijk / bestel product →'];
                }
                if ($selection->supplement_id) $item['catalogIds'][] = $selection->supplement_id;
                $item['selectionIds'][] = $selection->id;
                $quantity = $known ? round($dose['amount'] * $frequency * $weeks, 9) : null;
                $item['calculationComplete'] = $item['calculationComplete'] && $known;
                $item['quantity'] += $quantity ?? 0;
                $item['components'][] = [
                    'selectionId' => $selection->id, 'phaseId' => $phase->id,
                    'dosePerAdministration' => $dose['amount'] ?? null, 'unit' => $unit,
                    'administrationsPerDay' => $perDay, 'daysPerWeek' => 7,
                    'administrationsPerWeek' => $frequency, 'scheduledWeeks' => $weeks,
                    'administrations' => $frequency === null ? null : $frequency * $weeks,
                    'quantity' => $quantity,
                ];
                unset($item);
            }
        }
        foreach ($items as &$item) {
            $item['catalogIds'] = array_values(array_unique($item['catalogIds']));
            $item['quantity'] = $item['calculationComplete'] ? round($item['quantity'], 9) : null;
            $item['quantityLabel'] = $item['quantity'] === null ? null : $this->label($item['quantity'], $item['unit']);
        }
        unset($item);

        return array_values($items);
    }

    /** Reject ranges and prose rather than guessing a purchase quantity. */
    public function parseDose(?string $dosage): ?array
    {
        if (! preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*(kg|mg|g|ml|l|theelepels?|eetlepels?|druppels?|pil(?:len)?|capsules?)\s*(?:per gift)?\s*$/iu', $dosage ?? '', $match)) {
            return null;
        }
        $amount = (float) str_replace(',', '.', $match[1]);
        if ($amount <= 0 || ! is_finite($amount)) return null;
        $unit = mb_strtolower($match[2]);
        [$unit, $factor] = match ($unit) {
            'kg' => ['g', 1000], 'mg' => ['g', .001], 'l' => ['ml', 1000],
            'theelepel', 'theelepels' => ['theelepels', 1],
            'eetlepel', 'eetlepels' => ['eetlepels', 1],
            'druppel', 'druppels' => ['druppels', 1],
            'pil', 'pillen' => ['pillen', 1], 'capsule', 'capsules' => ['capsules', 1],
            default => [$unit, 1],
        };

        return ['amount' => $amount * $factor, 'unit' => $unit];
    }

    private function label(float $quantity, string $unit): string
    {
        if ($quantity >= 1000 && in_array($unit, ['g', 'ml'], true)) {
            $quantity /= 1000;
            $unit = $unit === 'g' ? 'kg' : 'l';
        }

        return rtrim(rtrim(number_format($quantity, 9, ',', ''), '0'), ',').' '.$unit;
    }

    private function safeUrl(?string $url): ?string
    {
        return $url && filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)
            ? $url : null;
    }
}
