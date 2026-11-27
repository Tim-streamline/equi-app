<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class EquiBoostAccess
{
    /** Keep in step with database/sql/library-equiboost-pause-access.sql. */
    private const END = "p.equiboost_started_at + interval '3 months' + p.equiboost_paused_seconds * interval '1 second'";

    public function expiresAt(string $userId): ?CarbonImmutable
    {
        $at = now()->utc()->format('Y-m-d H:i:s.u');
        $expiry = DB::table('protocols as p')->join('horses as h', 'h.id', '=', 'p.horse_id')
            ->where('h.owner_id', $userId)->where('h.status', 'active')->where('p.status', 'active')
            ->whereNotNull('p.published_at')->where('p.published_at', '<=', $at)
            ->where('p.equiboost_started_at', '<=', $at)
            // Time spent paused is added afterwards; while paused the protocol is not active and gives no access.
            ->whereRaw(self::END." > ?", [$at])
            ->selectRaw('max('.self::END.') as expires_at')->value('expires_at');

        return $expiry ? CarbonImmutable::parse($expiry, 'UTC') : null;
    }
}
