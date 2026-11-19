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

    public function test_once_daily_policy_updates_catalog_and_protocol_without_changing_dose(): void
    {
        $template = ProtocolTemplate::create(['name' => 'Once daily']);
        $definition = $template->phases()->create(['name' => 'Fase 1', 'order' => 1]);
        $catalog = $definition->supplements()->create(['name' => 'Psyliumzaad (bio)', 'supplement_type' => 'kruid', 'administrations_per_day' => 2]);
        $owner = User::factory()->create();
        $horse = Horse::create(['owner_id' => $owner->id, 'name' => 'Nova', 'status' => 'active']);
        $protocol = $horse->protocols()->create(['protocol_template_id' => $template->id, 'title' => 'Plan', 'status' => 'paused']);
        $phase = $protocol->phases()->create(['protocol_template_phase_id' => $definition->id, 'title' => 'Fase 1', 'state' => 'upcoming', 'order' => 1]);
        $selection = $phase->supplements()->create(['supplement_id' => $catalog->id, 'name' => $catalog->name, 'dosage' => '15 g', 'administrations_per_day' => 2]);
        $salt = $definition->supplements()->create(['name' => 'Schüsslerzout nr. 10 – Natrium sulfuricum', 'supplement_type' => 'supplement', 'administrations_per_day' => 2]);
        // The protocol snapshot can have a custom display name; its catalog link still identifies the salt.
        $saltSelection = $phase->supplements()->create(['supplement_id' => $salt->id, 'name' => 'Persoonlijk celzout', 'dosage' => '8 tabletten', 'administrations_per_day' => 2]);

        $migration = require database_path('migrations/2026_11_18_000003_set_protocol_products_once_daily.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, $catalog->fresh()->administrations_per_day);
        $this->assertSame(1, $selection->fresh()->administrationsPerDay());
        $this->assertSame('15 g', $selection->fresh()->dosage);
        $restore = require database_path('migrations/2026_11_18_000005_restore_cell_salts_twice_daily.php');
        $restore->up();
        $restore->up();
        $this->assertSame(2, $salt->fresh()->administrations_per_day);
        $this->assertSame(2, $saltSelection->fresh()->administrationsPerDay());
        $this->assertSame('8 tabletten', $saltSelection->fresh()->dosage);
        $this->assertSame(1, $catalog->fresh()->administrations_per_day);
    }

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
