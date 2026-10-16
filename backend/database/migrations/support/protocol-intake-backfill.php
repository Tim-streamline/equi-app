<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Frozen migration logic: query the historical schema directly, without model events,
// notifications, entitlement grants or dependencies on future application behaviour.
return new class
{
    public function run(): void
    {
        $this->repairReviewedNovaHistory();

        foreach (DB::table('horses')->orderBy('id')->get(['id', 'owner_id']) as $horse) {
            $protocols = DB::table('protocols')->where('horse_id', $horse->id)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('intake_bookings')->whereColumn('protocol_id', 'protocols.id'))->get();
            $intakes = DB::table('intake_bookings')->where('horse_id', $horse->id)->whereNull('protocol_id')->get();
            if ($protocols->count() === 1 && $intakes->count() === 1) {
                $this->link($intakes[0], $protocols[0]->id);
            } elseif ($protocols->isEmpty()) {
                foreach ($intakes as $intake) {
                    $id = (string) Str::uuid();
                    $values = ['id' => $id, 'horse_id' => $horse->id, 'title' => 'Intakeprotocol',
                        'status' => $intake->status === 'cancelled' ? 'archived' : 'draft',
                        'created_at' => $intake->created_at, 'updated_at' => $intake->updated_at];
                    DB::table('protocols')->insert($values);
                    $this->audit('protocol', $id, null, $values, 'Created a protocol for an existing intake.');
                    $this->link($intake, $id);
                }
            } elseif ($intakes->isEmpty()) {
                foreach ($protocols as $protocol) {
                    $id = (string) Str::uuid();
                    $values = ['id' => $id, 'protocol_id' => $protocol->id, 'horse_id' => $horse->id,
                        'user_id' => $horse->owner_id, 'status' => 'pending', 'intake_status' => 'draft',
                        'notes' => 'Aangemaakt bij datamigratie: voor dit bestaande protocol was geen intake gekoppeld. Er zijn geen antwoorden overgenomen.',
                        'created_at' => now(), 'updated_at' => now()];
                    DB::table('intake_bookings')->insert($values);
                    $this->audit('intake_booking', $id, null, $values, 'Created an explicitly empty intake for a legacy protocol.');
                }
            }
            // Multiple unmatched candidates are left for the final audit to reject.
        }
    }

    private function repairReviewedNovaHistory(): void
    {
        // User-approved mapping, 2026-10-14. IDs, ownership and empty intake were
        // inspected read-only on staging. Never generalise this to newest-wins.
        $horse = '019ff161-ea6a-70b7-9a47-b69dafe74975';
        $owner = '019ff161-da01-72fa-b1f2-c9e587ec8d5f';
        $old = '01a0639e-e9d5-7179-af49-2c2b8ac6b73c';
        $current = '01a07784-f09e-7322-a90c-69ea51f17864';
        $booking = '019ff162-1f74-7079-bc9d-b1e04a183b48';
        $protocols = DB::table('protocols')->where('horse_id', $horse)->get()->keyBy('id');
        $intakes = DB::table('intake_bookings')->where('horse_id', $horse)->get()->keyBy('id');
        if ($protocols->isEmpty() && $intakes->isEmpty()) {
            return;
        }
        // A retry after a schema-only rollback retains the already reconciled history.
        if ($protocols->count() === 2 && $intakes->count() === 2
            && ($protocols->get($old)?->status === 'archived')
            && ($protocols->get($current)?->status === 'active')
            && ($intakes->get($booking)?->protocol_id === $old)
            && $intakes->where('protocol_id', $current)->count() === 1) {
            return;
        }
        if ($protocols->count() !== 2 || $intakes->count() !== 1
            || $protocols->get($old)?->status !== 'active'
            || $protocols->get($current)?->status !== 'active'
            || DB::table('horses')->where('id', $horse)->value('owner_id') !== $owner
            || $intakes->get($booking)?->user_id !== $owner
            || $intakes->get($booking)?->status !== 'cancelled'
            || $intakes->get($booking)?->intake_status !== 'draft'
            || $intakes->get($booking)?->submitted_at !== null
            || $intakes->get($booking)?->protocol_id !== null
            || DB::table('intake_answers')->where('response_id', $booking)->exists()
            || DB::table('intake_attachments')->where('booking_id', $booking)->exists()) {
            throw new RuntimeException('Reviewed Nova protocol/intake history has changed. Reinspect horse '.$horse.' before deploying; no reconciliation changes have been committed.');
        }
        $after = ['status' => 'archived', 'archived_previous_status' => 'active'];
        DB::table('protocols')->where('id', $old)->update($after);
        $this->audit('protocol', $old, ['status' => 'active', 'archived_previous_status' => $protocols[$old]->archived_previous_status],
            $after, 'User-approved repair: retain Nova 2.0 as the single active protocol; archive Nova’s plan.');
        $this->link($intakes[$booking], $old);
    }

    private function link(object $intake, string $protocolId): void
    {
        DB::table('intake_bookings')->where('id', $intake->id)->update(['protocol_id' => $protocolId]);
        $this->audit('intake_booking', $intake->id, ['protocol_id' => null], ['protocol_id' => $protocolId], 'Linked the existing intake without changing its content or timestamps.');
    }

    private function audit(string $type, string $id, ?array $before, array $after, string $reason): void
    {
        DB::table('audit_logs')->insert(['actor_name' => 'Protocol/intake migration 2026-10-14',
            'action' => 'protocol_intake_migrated', 'target_type' => $type, 'target_id' => $id,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($after, JSON_THROW_ON_ERROR), 'reason' => $reason]);
    }
};
