<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The protocol policy is now one full prescribed dose daily for all products.
        // Keep dosage and previously recorded administrations intact.
        DB::transaction(function () {
            \App\Support\LibrarySyncLock::acquire();
            $history = app(\App\Support\ProtocolDayHistory::class);
            $affected = fn ($query) => $query->where('administrations_per_day', '!=', 1)
                ->orWhereHas('supplement', fn ($catalog) => $catalog->where('administrations_per_day', '!=', 1));
            $protocols = \App\Models\Protocol::whereNotNull('started_at')->whereNotNull('published_at')
                ->whereHas('phases.supplements', $affected)
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($protocols as $protocol) {
                $history->preserve($protocol, \Carbon\CarbonImmutable::now($history->timezone($protocol)));
            }
            $affectedIds = \App\Models\ProtocolPhaseSupplement::where($affected)->pluck('id');
            DB::table('supplements')->where('administrations_per_day', '!=', 1)
                ->update(['administrations_per_day' => 1]);
            DB::table('protocol_phase_supplements')->where('administrations_per_day', '!=', 1)
                ->update(['administrations_per_day' => 1]);
            foreach ($affectedIds->chunk(500) as $ids) {
                $selections = \App\Models\ProtocolPhaseSupplement::whereKey($ids->all())
                    ->with('phase.protocol.horse.owner.notificationPreferences')->get();
                foreach ($selections as $selection) {
                    $date = \Carbon\CarbonImmutable::now($history->timezone($selection->phase->protocol))->toDateString();
                    app(\App\Support\LegacyProtocolClient::class)->refreshDayDone($selection->id, $date, 1);
                }
            }
        });
    }

    public function down(): void
    {
        // Previous frequencies differed per product and cannot be reconstructed.
    }
};
