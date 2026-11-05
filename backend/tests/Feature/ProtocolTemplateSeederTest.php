<?php

namespace Tests\Feature;

use App\Models\DoseBuildup;
use App\Models\Horse;
use App\Models\ProtocolTemplate;
use App\Models\Supplement;
use App\Models\Therapist;
use App\Models\User;
use App\Support\ProtocolTemplateExport;
use Database\Seeders\ProtocolSeeder;
use Database\Seeders\ProtocolTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ProtocolTemplateSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_rejects_conflicting_definition_settings_and_rolls_back_catalog_changes(): void
    {
        $this->seed(ProtocolTemplateSeeder::class);
        $exported = app(ProtocolTemplateExport::class)->catalog();
        $before = $this->catalogRows();
        $definition = DoseBuildup::findOrFail(DoseBuildup::SEVEN_DAYS_ID)->snapshot();
        foreach (['type' => 'custom', 'start_dose_ratio' => 0.5, 'days' => 3,
            'schedule' => [['day' => 1, 'dose_ratio' => 1]]] as $field => $value) {
            $conflicting = $exported;
            $conflicting[0]['phases'][0]['name'] = 'Must roll back';
            $conflicting[0]['phases'][0]['supplements'][0]['dose_buildup'] = [...$definition, $field => $value];
            try {
                (new ProtocolTemplateSeeder)->import($conflicting);
                $this->fail('Expected the import to reject conflicting dosing settings.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($definition['id'], $exception->getMessage());
                $this->assertStringContainsString('conflicting dosing settings', $exception->getMessage());
            }
            $this->assertSame($before, $this->catalogRows());
            $this->assertSame($definition, DoseBuildup::findOrFail($definition['id'])->snapshot());
        }
    }

    public function test_import_reuses_matching_definitions_with_different_names_without_duplicates(): void
    {
        $this->seed(ProtocolTemplateSeeder::class);
        $exported = app(ProtocolTemplateExport::class)->catalog();
        $buildup = DoseBuildup::findOrFail(DoseBuildup::NONE_ID);
        $buildup->update(['name' => 'Locally renamed']);
        (new ProtocolTemplateSeeder)->import($exported);
        (new ProtocolTemplateSeeder)->import($exported);
        $this->assertSame('Locally renamed', $buildup->fresh()->name);
        $this->assertSame(3, DoseBuildup::count());
    }

    public function test_export_and_reseed_preserve_custom_buildup_without_overwriting_admin_changes(): void
    {
        $this->seed(ProtocolTemplateSeeder::class);
        $buildup = DoseBuildup::create(['name' => 'Custom export', 'type' => 'custom', 'days' => 2, 'start_dose_ratio' => 0.3,
            'schedule' => [['day' => 1, 'dose_ratio' => 0.3], ['day' => 2, 'dose_ratio' => 1]]]);
        $supplement = Supplement::firstOrFail();
        $supplement->update(['dose_buildup_id' => $buildup->id]);
        $name = $supplement->name;
        $phaseId = $supplement->protocol_template_phase_id;
        $exported = app(ProtocolTemplateExport::class)->catalog();
        $supplement->delete();
        $buildup->delete();
        (new ProtocolTemplateSeeder)->import($exported);
        $restored = Supplement::where('protocol_template_phase_id', $phaseId)->where('name', $name)->firstOrFail();
        $this->assertSame($buildup->snapshot(), $restored->doseBuildup->snapshot());
        $restored->update(['dose_buildup_id' => DoseBuildup::NONE_ID]);
        (new ProtocolTemplateSeeder)->import($exported);
        $this->assertSame(DoseBuildup::NONE_ID, $restored->fresh()->dose_buildup_id);
    }

    public function test_it_seeds_the_complete_saved_catalog_and_can_be_rerun_without_duplicates(): void
    {
        $this->seed(ProtocolTemplateSeeder::class);
        $this->assertSavedCatalog();
        $before = $this->catalogRows();

        $this->travel(1)->day();
        $this->seed(ProtocolTemplateSeeder::class);

        $this->assertSavedCatalog();
        $this->assertSame($before, $this->catalogRows());
        $this->assertDatabaseCount('protocols', 0);
        $this->assertDatabaseCount('horses', 0);
    }

    public function test_it_completes_an_existing_template_without_replacing_ids_or_customer_protocols(): void
    {
        $data = collect($this->savedCatalog())->first(fn (array $template) => count($template['phases']) > 0);
        $phaseData = $data['phases'][0];
        $template = ProtocolTemplate::query()->create(['name' => $data['name']]);
        $phase = $template->phases()->create(['order' => $phaseData['order'], 'name' => 'Old phase']);
        $horse = Horse::query()->create(['owner_id' => User::factory()->create()->id, 'name' => 'Nova']);
        $protocol = $template->protocols()->create(['horse_id' => $horse->id, 'title' => 'Existing customer protocol']);
        $unrelated = ProtocolTemplate::query()->create(['name' => 'Custom template']);
        $unrelatedPhase = $unrelated->phases()->create(['order' => 1, 'name' => 'Custom phase']);
        $beforeProtocol = $protocol->fresh()->getAttributes();
        $beforeUnrelatedPhase = $unrelatedPhase->fresh()->getAttributes();

        $this->seed(ProtocolTemplateSeeder::class);

        $this->assertSame($template->id, ProtocolTemplate::query()->where('name', $data['name'])->sole()->id);
        $this->assertSame($phase->id, $template->phases()->where('order', $phaseData['order'])->sole()->id);
        $this->assertSame($phaseData['name'], $phase->fresh()->name);
        $this->assertSame($beforeProtocol, $protocol->fresh()->getAttributes());
        $this->assertSame($beforeUnrelatedPhase, $unrelatedPhase->fresh()->getAttributes());
        $this->assertDatabaseCount('protocols', 1);
    }

    public function test_initial_seed_applies_the_catalog_per_day_frequency_and_defaults(): void
    {
        $this->seed(ProtocolTemplateSeeder::class);

        $twiceDaily = collect($this->savedCatalog())->flatMap(fn (array $template) => $template['phases'])
            ->flatMap(fn (array $phase) => $phase['supplements'])
            ->filter(fn (array $supplement) => ($supplement['administrations_per_day'] ?? 1) === 2)->pluck('name')->sort()->values();
        $this->assertCount(4, $twiceDaily);
        $this->assertSame($twiceDaily->all(), Supplement::query()->where('administrations_per_day', 2)->orderBy('name')->pluck('name')->unique()->values()->all());
        $this->assertSame(0, Supplement::query()->whereNotIn('administrations_per_day', [1, 2])->count());
        $this->assertSame(0, Supplement::query()->whereNotNull('product_url')->count());
    }

    public function test_rerunning_the_seeder_keeps_admin_managed_frequency_and_product_link(): void
    {
        $this->seed(ProtocolTemplateSeeder::class);
        $lijnzaad = Supplement::query()->where('name', 'Gekookt (bio) lijnzaad')->firstOrFail();
        $other = Supplement::query()->where('administrations_per_day', 1)->orderBy('name')->firstOrFail();
        $description = $other->description;
        $lijnzaad->update(['administrations_per_day' => 1, 'product_url' => 'https://shop.example.test/lijnzaad']);
        $other->update(['administrations_per_day' => 3, 'product_url' => 'https://shop.example.test/other', 'description' => 'Locally changed']);

        $this->seed(ProtocolTemplateSeeder::class);

        $this->assertSame(1, $lijnzaad->fresh()->administrations_per_day);
        $this->assertSame('https://shop.example.test/lijnzaad', $lijnzaad->fresh()->product_url);
        $this->assertSame(3, $other->fresh()->administrations_per_day);
        $this->assertSame('https://shop.example.test/other', $other->fresh()->product_url);
        // Everything else is still synchronised from the saved catalog, keeping the row ID.
        $this->assertSame($description, $other->fresh()->description);
        $this->assertSame($lijnzaad->id, Supplement::query()->where('name', 'Gekookt (bio) lijnzaad')->sole()->id);
    }

    public function test_new_catalog_products_receive_exported_frequency_and_link(): void
    {
        $catalog = [[
            'name' => 'Exported template',
            'phases' => [[
                'order' => 1, 'name' => 'Fase 1', 'description' => null, 'required' => true,
                'start_after_previous_phase_weeks' => 0, 'weeks' => [1, 2],
                'supplements' => [
                    ['name' => 'Driemaal', 'supplement_type' => 'kruid', 'administrations_per_day' => 3,
                        'product_url' => 'https://shop.example.test/driemaal', 'weeks' => [1]],
                    ['name' => 'Zonder velden', 'supplement_type' => 'kruid', 'weeks' => [2]],
                ],
            ]],
        ]];

        (new ProtocolTemplateSeeder)->import($catalog);

        $created = Supplement::query()->where('name', 'Driemaal')->sole();
        $this->assertSame(3, $created->administrations_per_day);
        $this->assertSame('https://shop.example.test/driemaal', $created->product_url);
        $default = Supplement::query()->where('name', 'Zonder velden')->sole();
        $this->assertSame(1, $default->fresh()->administrations_per_day);
        $this->assertNull($default->fresh()->product_url);
    }

    public function test_export_retains_frequency_and_product_link_for_a_lossless_reseed(): void
    {
        $this->seed(ProtocolTemplateSeeder::class);
        $lijnzaad = Supplement::query()->where('name', 'Gekookt (bio) lijnzaad')->firstOrFail();
        $lijnzaad->update(['administrations_per_day' => 3, 'product_url' => 'https://shop.example.test/lijnzaad']);

        $exported = app(ProtocolTemplateExport::class)->catalog();
        $supplements = $this->supplementsByKey($exported);
        $this->assertTrue($supplements->every(fn (array $supplement) => array_key_exists('administrations_per_day', $supplement) && array_key_exists('product_url', $supplement)));
        $row = $supplements->first(fn (array $supplement) => $supplement['name'] === 'Gekookt (bio) lijnzaad');
        $this->assertSame(3, $row['administrations_per_day']);
        $this->assertSame('https://shop.example.test/lijnzaad', $row['product_url']);
        // Every other field in the tracked catalog survives an export unchanged.
        $saved = $this->supplementsByKey($this->savedCatalog());
        $this->assertSame($saved->keys()->sort()->values()->all(), $supplements->keys()->sort()->values()->all());
        foreach ($saved as $key => $savedSupplement) {
            foreach (Arr::except($savedSupplement, $savedSupplement['name'] === 'Gekookt (bio) lijnzaad' ? ['administrations_per_day'] : []) as $field => $value) {
                $this->assertEquals($value, $supplements[$key][$field], $key.': '.$field);
            }
        }

        // A fresh install seeded from that export gets the edited values.
        DB::table('supplement_weeks')->delete();
        Supplement::query()->delete();
        (new ProtocolTemplateSeeder)->import($exported);
        $this->assertSame(3, Supplement::query()->where('name', 'Gekookt (bio) lijnzaad')->sole()->administrations_per_day);
        $this->assertSame('https://shop.example.test/lijnzaad', Supplement::query()->where('name', 'Gekookt (bio) lijnzaad')->sole()->product_url);
    }

    public function test_the_demo_protocol_seeder_preserves_the_complete_template_catalog(): void
    {
        $this->seed(ProtocolTemplateSeeder::class);
        $before = $this->catalogRows();
        $user = User::factory()->create(['email' => UserSeeder::ANCHOR_EMAIL]);
        Horse::query()->create(['owner_id' => $user->id, 'name' => 'Nova']);
        Therapist::query()->create(['name' => 'Shelley', 'title' => 'De Paardentherapeut', 'verified' => true]);

        $this->seed(ProtocolSeeder::class);
        $this->seed(ProtocolSeeder::class);

        $this->assertSame($before, $this->catalogRows());
        $this->assertDatabaseCount('protocols', 1);
    }

    public function test_a_failure_rolls_back_the_entire_catalog(): void
    {
        Supplement::creating(function (): void {
            throw new RuntimeException('Simulated supplement failure');
        });

        try {
            $this->seed(ProtocolTemplateSeeder::class);
            $this->fail('Expected the simulated failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated supplement failure', $exception->getMessage());
        } finally {
            Supplement::flushEventListeners();
        }

        foreach (array_keys($this->catalogRows()) as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function assertSavedCatalog(): void
    {
        $data = $this->savedCatalog();
        $this->assertNotEmpty($data);
        $this->assertDatabaseCount('protocol_templates', count($data));

        foreach ($data as $templateData) {
            $template = ProtocolTemplate::query()->where('name', $templateData['name'])
                ->with(['phases.weeks', 'phases.supplements.weeks'])->sole();
            $this->assertCount(count($templateData['phases']), $template->phases);

            foreach ($templateData['phases'] as $phaseData) {
                $phase = $template->phases->sole('order', $phaseData['order']);
                $fields = Arr::except($phaseData, ['weeks', 'supplements']);
                $this->assertSame($fields, $phase->only(array_keys($fields)));
                $this->assertSame($phaseData['weeks'], $phase->weeks->pluck('number')->all());
                $this->assertCount(count($phaseData['supplements']), $phase->supplements);

                foreach ($phaseData['supplements'] as $supplementData) {
                    $supplement = $phase->supplements->sole('name', $supplementData['name']);
                    foreach (Arr::except($supplementData, ['weeks']) as $field => $value) {
                        $this->assertEquals($value, $supplement->getRawOriginal($field), $field);
                    }
                    $this->assertSame($supplementData['weeks'], $supplement->weeks->pluck('number')->all());
                    $this->assertEmpty($supplement->weeks->pluck('id')->diff($phase->weeks->pluck('id')));
                }
            }
        }
    }

    private function supplementsByKey(array $catalog): Collection
    {
        return collect($catalog)->flatMap(fn (array $template) => collect($template['phases'])->flatMap(
            fn (array $phase) => collect($phase['supplements'])->mapWithKeys(
                fn (array $supplement) => [$template['name'].' | '.$phase['order'].' | '.$supplement['name'] => $supplement],
            ),
        ));
    }

    private function savedCatalog(): array
    {
        return json_decode(file_get_contents(database_path('data/protocol-templates.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function catalogRows(): array
    {
        $rows = [];
        foreach (['protocol_templates', 'protocol_template_phases', 'protocol_template_phase_weeks', 'supplements', 'supplement_weeks'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all();
        }

        return $rows;
    }
}
