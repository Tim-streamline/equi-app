<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\ProtocolTemplate;
use App\Models\Supplement;
use App\Models\Therapist;
use App\Models\User;
use Database\Seeders\ProtocolSeeder;
use Database\Seeders\ProtocolTemplateSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ProtocolTemplateSeederTest extends TestCase
{
    use RefreshDatabase;

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
