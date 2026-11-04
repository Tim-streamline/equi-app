<?php

namespace App\Support;

use App\Models\ProtocolSupplementIntake;

/**
 * Keeps released app versions (without ClientCapabilities::PROTOCOL_DOSES) on their
 * original contract: one checkbox per product per day, order rows identified by the
 * phase selection, and no owner start button.
 *
 * Their single checkbox stands for the whole product-day: it is checked only when
 * every scheduled dose is done, and checking or unchecking it applies to all doses.
 */
class LegacyProtocolClient
{
    /**
     * Recompute the whole-day checkmark that released apps sync from the first-dose row
     * (see powersync/sync_rules.yaml). $doses is the dose count of that day's plan.
     */
    public function refreshDayDone(string $itemId, string $date, int $doses): void
    {
        $rows = ProtocolSupplementIntake::query()->where('protocol_phase_supplement_id', $itemId)
            ->where('date', $date)->get();
        // If the evening dose is recorded first (or morning is deleted), the old app
        // still needs one stable row for its whole-day checkbox.
        if ($rows->isNotEmpty() && ! $rows->contains('administration', 1)) {
            $first = $rows->first();
            ProtocolSupplementIntake::create(['protocol_phase_supplement_id' => $itemId,
                'horse_id' => $first->horse_id, 'date' => $date, 'dosage' => $first->dosage,
                'administration' => 1, 'done' => false]);
        }
        $done = $doses > 0 && collect(range(1, $doses))
            ->every(fn ($administration) => (bool) $rows->firstWhere('administration', $administration)?->done);
        ProtocolSupplementIntake::query()->where('protocol_phase_supplement_id', $itemId)->where('date', $date)
            ->where('day_done', '!=', $done)->update(['day_done' => $done, 'updated_at' => now()]);
    }

    /** One row per product, checked only when all of its doses are done. */
    public function dailyItems(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            if (isset($rows[$item['id']])) {
                $rows[$item['id']]['done'] = $rows[$item['id']]['done'] && $item['done'];

                continue;
            }
            $rows[$item['id']] = array_diff_key($item, ['administration' => true]);
        }

        return array_values($rows);
    }

    /** The calendar day sheet; its state still reflects every dose. */
    public function day(array $day): array
    {
        return ['items' => $this->dailyItems($day['items'])] + $day;
    }

    public function dashboard(array $data): array
    {
        if (! ($data['protocol'] ?? null)) {
            return $data;
        }
        $protocol = $data['protocol'];
        $today = $this->dailyItems($protocol['today']['items']);
        $done = count(array_filter($today, fn ($item) => $item['done']));
        $protocol['today'] = ['items' => $today, 'done' => $done, 'total' => count($today),
            'percentage' => $today ? (int) round($done / count($today) * 100) : 0];
        // Released apps look up order rows by the phase selection IDs they can see.
        $selections = collect($protocol['phases'])->flatMap(fn ($phase) => $phase['supplements'])
            ->filter(fn ($selection) => count($selection['weekNumbers']) > 0)->values()->all();
        if ($protocol['preparing']) {
            // They cannot start a plan or show the preparation screen. All phases are still
            // locked there, so list the purchase totals in a notice they already render.
            $protocol['notifications'][] = [
                'id' => 'preparation-'.$protocol['id'], 'type' => 'preparation',
                'title' => 'Je herstelplan staat klaar',
                'body' => 'Het protocol is nog niet gestart. Spreek de startdatum af met je therapeut; vanaf die dag zie je hier wat je elke dag geeft. Hieronder staat het volledige besteloverzicht voor je voorbereiding. De aparte bestellijst is beschikbaar nadat het protocol is gestart:',
                'items' => array_map(fn ($row) => ['id' => $row['id'], 'name' => $row['name'], 'phaseTitle' => '',
                    'dosage' => $row['quantityLabel'] ? $row['quantityLabel'].' totaal' : 'Hoeveelheid volgt'], $protocol['orderItems']),
            ];
        }
        $protocol['orderItems'] = $selections;
        unset($protocol['orderDiscount']);
        $data['protocol'] = $protocol;

        return $data;
    }
}
