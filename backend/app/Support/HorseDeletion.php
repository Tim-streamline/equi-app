<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HorseDeletion
{
    /** The foreign keys cascade horse-owned data and detach account history. */
    public function preview(Horse $horse): array
    {
        $groups = [
            'removed' => [
                'bookings' => ['intake_bookings', 'Intakes en bookings (inclusief antwoorden en bijlagen)'],
                'protocols' => ['protocols', 'Protocollen (inclusief fases en adviezen)'],
                'observations' => ['observations', 'Observaties (inclusief fotokoppelingen)'],
                'shares' => ['horse_shares', 'Gedeelde toegang'],
                'stats' => ['horse_stats', 'Paardstatistieken'],
                'timeline' => ['timeline_events', 'Tijdlijnitems'],
                'supplement_intakes' => ['protocol_supplement_intakes', 'Supplementregistraties'],
            ],
            'detached' => [
                'scans' => ['scan_results', 'Scans'],
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
            // Match archive/restore: library access triggers must not reverse the row lock order.
            LibrarySyncLock::acquire();
            // Lock before checking links: concurrent FK writes must wait for this deletion.
            $locked = Horse::query()->lockForUpdate()->findOrFail($horse->id);
            $links = $this->preview($locked);
            $before = $locked->getAttributes();
            $bookings = IntakeBooking::where('horse_id', $locked->id)->lockForUpdate()->get();
            $paths = IntakeAttachment::whereIn('booking_id', $bookings->modelKeys())->pluck('path')->all();
            $locked->protocols()->delete();
            foreach ($bookings as $booking) {
                // Answers, attachments and reminder deliveries cascade with the intake.
                // The horse deletion below cascades protocols, intakes and their answers.
                AuditLogger::deleted($booking);
            }
            $locked->delete();
            AuditLogger::log('deleted', $locked, before: $before + ['linked_data' => $links]);
            DB::afterCommit(function () use ($paths) {
                // Files must survive a database rollback. Cleanup failure cannot undo a commit.
                try {
                    if ($paths && ! Storage::disk('local')->delete($paths)) {
                        throw new \RuntimeException('Could not clean up deleted horse intake attachments.');
                    }
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        });
    }
}
