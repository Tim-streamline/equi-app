<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\ProtocolTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplementAdministrationsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_marks_only_the_known_twice_daily_products(): void
    {
        $known = [
            'Gekookt (bio) lijnzaad',
            'Psyliumzaad (bio)',
            'Schüsslerzout nr. 10 – Natrium sulfuricum',
            'Schüsslerzout nr. 27 – Kalium bichromicum',
        ];
        // Lookalikes the former substring match also caught, including custom snapshot names.
        $unrelated = ['Lijnzaadolie', 'Psylliumzaad', 'Psyllium husk', 'Celzout mix', 'Schusslerzout nr. 11', 'Zink'];

        $template = ProtocolTemplate::create(['name' => 'Migration catalog']);
        $definition = $template->phases()->create(['name' => 'Fase 1', 'order' => 1]);
        $owner = User::factory()->create();
        $horse = Horse::create(['owner_id' => $owner->id, 'name' => 'Nova', 'status' => 'active']);
        $protocol = $horse->protocols()->create(['protocol_template_id' => $template->id, 'title' => 'Plan', 'status' => 'paused']);
        $phase = $protocol->phases()->create(['protocol_template_phase_id' => $definition->id, 'title' => 'Fase 1',
            'state' => 'upcoming', 'order' => 1]);
        foreach ([...$known, ...$unrelated] as $name) {
            $definition->supplements()->create(['name' => $name, 'supplement_type' => 'kruid']);
            $phase->supplements()->create(['name' => $name, 'dosage' => '10 g']);
        }

        $migration = require database_path('migrations/2026_11_01_000002_add_supplement_product_url.php');
        $migration->down();
        $migration->up();

        foreach (['supplements', 'protocol_phase_supplements'] as $table) {
            $frequencies = DB::table($table)->pluck('administrations_per_day', 'name')->map(fn ($value) => (int) $value)->all();
            foreach ($known as $name) {
                $this->assertSame(2, $frequencies[$name], "{$table}: {$name}");
            }
            foreach ($unrelated as $name) {
                $this->assertSame(1, $frequencies[$name], "{$table}: {$name}");
            }
        }
    }
}
