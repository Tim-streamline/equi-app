<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class ProtocolIntakeIntegrity
{
    /** IDs only: safe to print during a deployment; never guesses historical associations. */
    public function issues(): array
    {
        return array_filter([
            'unlinked_intakes' => DB::table('intake_bookings')->whereNull('protocol_id')->pluck('id')->all(),
            'protocols_without_intake' => DB::table('protocols')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('intake_bookings')->whereColumn('protocol_id', 'protocols.id'))->pluck('id')->all(),
            'intakes_without_horse' => DB::table('intake_bookings')->whereNull('horse_id')->pluck('id')->all(),
            'mismatched_horses' => DB::table('intake_bookings as i')->join('protocols as p', 'p.id', '=', 'i.protocol_id')->whereColumn('i.horse_id', '!=', 'p.horse_id')->pluck('i.id')->all(),
            'horses_with_multiple_active_protocols' => DB::table('protocols')->where('status', 'active')->groupBy('horse_id')->havingRaw('count(*) > 1')->pluck('horse_id')->all(),
        ]);
    }
}
