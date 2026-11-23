<?php

use App\Models\ProtocolPhaseSupplement;
use App\Support\LibrarySyncLock;
use App\Support\ProtocolDayHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // The sentence the bundled templates used for products that were given twice daily.
    private const TWICE = 'Geef de dosering bij voorkeur 2× daags. Bij 2× daags geef je dus tweemaal de volledige hoeveelheid.';

    private const ONCE = 'Geef de volledige berekende dosering 1× per dag.';

    // PankrEMS advised halving the dose over two feeds. It is one full dose a day like the rest.
    private const HALVED = '/Bij voorkeur 2\s?[x×] daags de helft van de aanbevolen dosering door de bijvoeding mengen\.\s*Anders 1\s?[x×] daags de aanbevolen dosering door de bijvoeding mengen\./u';

    private const HALVED_LIKE = '%daags de helft van de aanbevolen dosering door de bijvoeding mengen.%';

    private const HALVED_ONCE = '1× daags de berekende dosering door de bijvoeding mengen.';

    public function up(): void
    {
        // The once-daily migration changed the frequency only, so the app showed '1× per dag'
        // above an explanation that still said to give the dose twice. Only these sentences
        // are replaced; the rest of an explanation, and any other wording, stays.
        DB::transaction(function () {
            LibrarySyncLock::acquire();
            $history = app(ProtocolDayHistory::class);
            $outdated = fn ($query) => $query->where('instructions', 'like', '%'.self::TWICE.'%')
                ->orWhere('instructions', 'like', self::HALVED_LIKE);
            // Products that are still given more often (the cell salts) keep their text.
            $selections = ProtocolPhaseSupplement::with('supplement', 'phase.protocol.horse.owner.notificationPreferences')
                ->where($outdated)->orderBy('id')->get()
                ->filter(fn (ProtocolPhaseSupplement $selection) => $selection->administrationsPerDay() === 1)
                ->filter(fn (ProtocolPhaseSupplement $selection) => $this->reword($selection->instructions) !== $selection->instructions);
            // Days that have passed keep the explanation the owner saw then.
            foreach ($selections->map(fn ($selection) => $selection->phase->protocol)->unique('id') as $protocol) {
                $history->preserve($protocol, CarbonImmutable::now($history->timezone($protocol)));
            }
            foreach ($selections as $selection) {
                DB::table('protocol_phase_supplements')->where('id', $selection->id)
                    ->update(['instructions' => $this->reword($selection->instructions), 'updated_at' => now()]);
            }
            $catalog = DB::table('supplements')->where('administrations_per_day', 1)
                ->where($outdated)->orderBy('id')->get(['id', 'instructions']);
            foreach ($catalog as $product) {
                DB::table('supplements')->where('id', $product->id)
                    ->update(['instructions' => $this->reword($product->instructions), 'updated_at' => now()]);
            }
        });
    }

    private function reword(string $instructions): string
    {
        return preg_replace(self::HALVED, self::HALVED_ONCE, str_replace(self::TWICE, self::ONCE, $instructions));
    }

    public function down(): void
    {
        // Explanations may have been edited in admin since; the old sentence is not restored.
    }
};
