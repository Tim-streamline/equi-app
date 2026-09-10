<?php

namespace App\Support;

use App\Models\Horse;
use Illuminate\Support\Facades\DB;

class HorseDeletion
{
    /** The foreign keys cascade horse-owned data and detach account history. */
    public function preview(Horse $horse): array
    {
        $groups = [
            'removed' => [
                'protocols' => ['protocols', 'Protocollen (inclusief fases en adviezen)'],
                'observations' => ['observations', 'Observaties (inclusief fotokoppelingen)'],
                'shares' => ['horse_shares', 'Gedeelde toegang'],
                'stats' => ['horse_stats', 'Paardstatistieken'],
                'timeline' => ['timeline_events', 'Tijdlijnitems'],
                'supplement_intakes' => ['protocol_supplement_intakes', 'Supplementregistraties'],
            ],
            'detached' => [
                'scans' => ['scan_results', 'Scans'],
                'bookings' => ['intake_bookings', 'Bookings'],
                'intakes' => ['intake_responses', 'Intakes'],
                'chats' => ['chat_sessions', 'Chats'],
                'exports' => ['data_exports', 'Gegevensexports'],
            ],
        ];

        $result = [];
        foreach ($groups as $group => $tables) {
            foreach ($tables as $key => [$table, $label]) {
                $result[$group][$key] = [
                    'label' => $label,
                    'count' => DB::table($table)->where('horse_id', $horse->id)->count(),
                ];
            }
        }

        return $result;
    }

    public function delete(Horse $horse): void
    {
        DB::transaction(function () use ($horse) {
            // Lock before checking links: concurrent FK writes must wait for this deletion.
            $locked = Horse::query()->lockForUpdate()->findOrFail($horse->id);
            $links = $this->preview($locked);
            $before = $locked->getAttributes();
            $locked->delete();
            AuditLogger::log('deleted', $locked, before: $before + ['linked_data' => $links]);
        });
    }
}
