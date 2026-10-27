<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class EquiBoostAccess
{
    public function expiresAt(string $userId): ?CarbonImmutable
    {
        $at = now()->utc()->format('Y-m-d H:i:s.u');
        $expiry = DB::table('protocols as p')->join('horses as h', 'h.id', '=', 'p.horse_id')
            ->where('h.owner_id', $userId)->where('h.status', 'active')->where('p.status', 'active')
            ->whereNotNull('p.published_at')->where('p.published_at', '<=', $at)
            ->where('p.equiboost_started_at', '<=', $at)
            ->whereRaw("p.equiboost_started_at + interval '3 months' > ?", [$at])
            ->selectRaw("max(p.equiboost_started_at + interval '3 months') as expires_at")->value('expires_at');

        return $expiry ? CarbonImmutable::parse($expiry, 'UTC') : null;
    }
}
