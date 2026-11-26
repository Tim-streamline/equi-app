<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $names = ['Schüsslerzout nr. 10 – Natrium sulfuricum', 'Schüsslerzout nr. 27 – Kalium bichromicum'];
        DB::transaction(function () use ($names) {
            \App\Support\LibrarySyncLock::acquire();
            $history = app(\App\Support\ProtocolDayHistory::class);
            $protocols = \App\Models\Protocol::whereNotNull('started_at')->whereNotNull('published_at')
                ->whereHas('phases.supplements', fn ($query) => $query->whereIn('name', $names)
                    ->orWhereHas('supplement', fn ($catalog) => $catalog->whereIn('name', $names)))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($protocols as $protocol) {
                $history->preserve($protocol, \Carbon\CarbonImmutable::now($history->timezone($protocol)));
            }
            DB::table('supplements')->whereIn('name', $names)->update(['administrations_per_day' => 2]);
            $selections = \App\Models\ProtocolPhaseSupplement::whereIn('name', $names)
                ->orWhereHas('supplement', fn ($catalog) => $catalog->whereIn('name', $names))->get();
            foreach ($selections as $selection) {
                $selection->update(['administrations_per_day' => 2]);
                $date = \Carbon\CarbonImmutable::now($history->timezone($selection->phase->protocol))->toDateString();
                app(\App\Support\LegacyProtocolClient::class)->refreshDayDone($selection->id, $date, $selection->fresh()->administrationsPerDay());
            }
        });
    }

    public function down(): void
    {
        // Do not overwrite frequencies subsequently maintained in admin.
    }
};
