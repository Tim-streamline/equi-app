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
        $saltSelection = $phase->supplements()->create(['supplement_id' => $salt->id, 'name' => 'Persoonlijk celzout', 'dosage' => '8 tabletten', 'administrations_per_day' => 1]);

        // A catalog change affects today's whole-day checkbox even if the selection already says once daily.
        $unaffected = $phase->supplements()->create(['name' => 'Already once daily', 'administrations_per_day' => 1]);
        $today = now('Europe/Amsterdam')->toDateString();
        foreach ([$selection, $saltSelection] as $item) {
            $item->intakes()->create(['horse_id' => $horse->id, 'date' => $today, 'administration' => 1, 'done' => true]);
        }
        $loaded = [];
        \App\Models\ProtocolPhaseSupplement::retrieved(function ($selection) use (&$loaded) {
            $loaded[] = $selection->id;
        });

        $migration = require database_path('migrations/2026_11_18_000003_set_protocol_products_once_daily.php');
        $migration->up();
        $migration->up();

        $this->assertNotContains($unaffected->id, $loaded);
        foreach ([$selection, $saltSelection] as $item) {
            $this->assertTrue($item->intakes()->sole()->day_done);
        }
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

    public function test_once_daily_products_lose_the_twice_daily_sentence_and_nothing_else(): void
    {
        $twice = 'Geef de dosering bij voorkeur 2× daags. Bij 2× daags geef je dus tweemaal de volledige hoeveelheid.';
        $once = 'Geef de volledige berekende dosering 1× per dag.';
        $template = ProtocolTemplate::create(['name' => 'Instructions']);
        $definition = $template->phases()->create(['name' => 'Fase 1', 'order' => 1]);
        $owner = User::factory()->create();
        $horse = Horse::create(['owner_id' => $owner->id, 'name' => 'Nova', 'status' => 'active']);
        $protocol = $horse->protocols()->create(['protocol_template_id' => $template->id, 'title' => 'Plan', 'status' => 'active']);
        $phase = $protocol->phases()->create(['protocol_template_phase_id' => $definition->id, 'title' => 'Fase 1', 'state' => 'upcoming', 'order' => 1]);
        $catalog = $definition->supplements()->create(['name' => 'Psyliumzaad (bio)', 'supplement_type' => 'kruid', 'administrations_per_day' => 1,
            'instructions' => 'Laten weken. '.$twice]);
        $selection = $phase->supplements()->create(['supplement_id' => $catalog->id, 'name' => $catalog->name, 'dosage' => '15 g', 'administrations_per_day' => 1,
            'instructions' => 'Eigen aanwijzing van de therapeut. '.$twice.' Vers bereiden.']);
        // Still given twice a day, so its explanation is still right.
        $salt = $definition->supplements()->create(['name' => 'Schüsslerzout nr. 10 – Natrium sulfuricum', 'supplement_type' => 'supplement',
            'administrations_per_day' => 2, 'instructions' => $twice]);
        $saltSelection = $phase->supplements()->create(['supplement_id' => $salt->id, 'name' => 'Persoonlijk celzout', 'dosage' => '8 tabletten',
            'administrations_per_day' => 2, 'instructions' => $twice]);
        $other = $phase->supplements()->create(['name' => 'Heemstwortel', 'dosage' => '10 g', 'administrations_per_day' => 1,
            'instructions' => 'Geef 1× of bij voorkeur 2× daags steeds de volledige berekende hoeveelheid.']);

        // PankrEMS used to halve the dose over two feeds; admin-saved text has Windows line ends.
        $halved = "Bij voorkeur 2x daags de helft van de aanbevolen dosering door de bijvoeding mengen.\nAnders 1x daags de aanbevolen dosering door de bijvoeding mengen.";
        $pankrems = $definition->supplements()->create(['name' => 'PankrEMS', 'supplement_type' => 'supplement', 'administrations_per_day' => 1, 'instructions' => $halved]);
        $pankremsSelection = $phase->supplements()->create(['supplement_id' => $pankrems->id, 'name' => 'PankrEMS', 'dosage' => '20 g', 'administrations_per_day' => 1,
            'instructions' => str_replace("\n", "\r\n", $halved).' Niet bij koliek.']);

        $migration = require database_path('migrations/2026_11_21_000001_reword_once_daily_product_instructions.php');
        $migration->up();
        $migration->up();

        $this->assertSame('1× daags de berekende dosering door de bijvoeding mengen.', $pankrems->fresh()->instructions);
        $this->assertSame('1× daags de berekende dosering door de bijvoeding mengen. Niet bij koliek.', $pankremsSelection->fresh()->instructions);

        $this->assertSame('Laten weken. '.$once, $catalog->fresh()->instructions);
        $this->assertSame('Eigen aanwijzing van de therapeut. '.$once.' Vers bereiden.', $selection->fresh()->instructions);
        $this->assertSame($twice, $salt->fresh()->instructions);
        $this->assertSame($twice, $saltSelection->fresh()->instructions);
        $this->assertSame('Geef 1× of bij voorkeur 2× daags steeds de volledige berekende hoeveelheid.', $other->fresh()->instructions);
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
