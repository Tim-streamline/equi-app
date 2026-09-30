<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\BewegingAdvies;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\ManagementAdvies;
use App\Models\Protocol;
use App\Models\ProtocolTemplate;
use App\Models\User;
use App\Models\VoedingAdvies;
use App\Support\HorseDashboard;
use App\Support\ProtocolNutrition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProtocolEditorIntakeTest extends TestCase
{
    use RefreshDatabase;

    private Horse $horse;

    private IntakeBooking $intake;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create();
        $this->horse = Horse::create(['name' => 'R', 'owner_id' => $owner->id, 'weight_kg' => 54, 'status' => 'active']);
        $this->intake = IntakeBooking::create(['horse_id' => $this->horse->id, 'user_id' => $owner->id, 'submitted_at' => now(), 'status' => 'pending']);
        $this->answer('gewicht', 535);
        $this->answer('huidige-bijvoeding', [['merk' => 'Metazoa', 'product' => 'Esparcette', 'hoeveelheid' => '250 g', 'voerbeurten' => '2']]);
        $this->answer('balancer', [['merk' => 'Balancer', 'product' => 'Compleet', 'hoeveelheid' => '50 g', 'voerbeurten' => '1']]);
        $this->answer('huidig-extra', [['merk' => 'Supplement', 'product' => 'Magnesium', 'dosering' => '10 g']]);
        $this->answer('snacks-aanwezig', 'Ja');
        $this->answer('snacks-details', [['wat' => 'Wortels', 'hoeveel' => '1 stuk', 'hoevaak' => 'Dagelijks']]);
        $this->answer('mineralen-toegang', ['gewone liksteen', 'Himalaya zoutsteen', 'anders']);
        $this->answer('mineralen-toegang-anders', 'Extra mineralenbuffet');
        $this->answer('bijvoeding-historie', [['product' => 'Historisch voer']]);
        $this->answer('historie-extra', [['product' => 'Historisch supplement']]);
        $template = ProtocolTemplate::create(['name' => 'Intake test']);
        $phase = $template->phases()->create(['name' => 'Eerste fase', 'description' => 'Persoonlijk herstel', 'order' => 0, 'required' => true]);
        foreach (range(1, 4) as $n) {
            $phase->weeks()->create(['number' => $n]);
        }
        $items = [];
        foreach (['Gekookt lijnzaad' => 100, 'Heemstwortel' => 15, 'Psylliumzaad' => 200] as $name => $dose) {
            $supplement = $phase->supplements()->create(['name' => $name, 'dosis_type' => 'per_600_kg', 'dosis' => $dose, 'unit' => 'g', 'supplement_type' => 'kruid', 'add_by_default' => true]);
            $items[] = ['supplement_id' => $supplement->id, 'dosage_mode' => 'automatic', 'dosage' => '9 g', 'instructions' => 'Bereiden met water.', 'aantal_per_week' => 4, 'week_numbers' => [1, 3]];
        }
        $advice = VoedingAdvies::create(['title' => 'Voedingsadvies op maat', 'description' => 'Verdeel over kleine porties.']);
        $this->payload = ['horse_id' => $this->horse->id, 'protocol_template_id' => $template->id, 'title' => 'Protocol R', 'status' => 'active', 'started_at' => '2026-09-01', 'published' => false,
            'customer_settings' => ['use_target_weight' => false], 'analysis' => ['summary' => 'Rustig herstellen.', 'observations' => ['Let op de mest.']], 'advice' => [],
            'voeding_advies_ids' => [$advice->id], 'management_advies_ids' => [], 'beweging_advies_ids' => [],
            'phases' => [['protocol_template_phase_id' => $phase->id, 'client_key' => 'one', 'week_count' => 4, 'start_after_previous_phase_weeks' => null, 'supplements' => $items]]];
        $admin = AdminUser::create(['name' => 'Tester', 'email' => 'protocol-intake@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $this->actingAs($admin, 'admin');
    }

    private function answer(string $field, mixed $value): void
    {
        $this->intake->answers()->updateOrCreate(['field_id' => $field], ['section_id' => $field === 'gewicht' ? 'paard' : 'voeding', 'value' => json_encode($value)]);
    }

    public function test_submitted_intake_weight_drives_editor_and_authoritative_saved_doses(): void
    {
        $this->get('/admin/protocols/create?horse_id='.$this->horse->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('intakeWeights.'.$this->horse->id, 535));
        $this->post('/admin/protocols', $this->payload)->assertSessionHasNoErrors();
        $protocol = Protocol::firstOrFail();
        $this->assertSame(['90 g', '15 g', '180 g'], $protocol->phases->first()->supplements->pluck('dosage')->all());
        $this->assertSame(['automatic'], $protocol->phases->first()->supplements->pluck('dosage_mode')->unique()->values()->all());
        $preview = $this->postJson('/admin/protocols/preview', $this->payload)->assertOk();
        $preview->assertJsonPath('protocol.nutrition.roughage.minimumKg', 10.7)->assertJsonPath('protocol.nutrition.roughage.maximumKg', 16.05);
    }

    public function test_actual_weight_target_weight_manual_dose_and_frequency_remain_independent(): void
    {
        $payload = $this->payload;
        $payload['customer_settings'] = ['weight_kg' => 600, 'use_target_weight' => true, 'target_weight_kg' => 400];
        $payload['phases'][0]['supplements'][0]['dosage_mode'] = 'manual';
        $payload['phases'][0]['supplements'][0]['dosage'] = '82 g persoonlijk';
        $payload['phases'][0]['supplements'][1]['aantal_per_week'] = 7;
        $this->post('/admin/protocols', $payload)->assertSessionHasNoErrors();
        $protocol = Protocol::firstOrFail();
        $this->assertSame(['82 g persoonlijk', '15 g', '200 g'], $protocol->phases->first()->supplements->pluck('dosage')->all());
        $data = app(HorseDashboard::class)->protocol($protocol, CarbonImmutable::parse('2026-09-01'), answers: app(ProtocolNutrition::class)->answers($this->horse));
        $this->assertEquals(8, $data['nutrition']['roughage']['minimumKg']);
        $this->assertSame('Voedingsadvies op maat', $data['nutrition']['advice'][0]['title']);
        $this->get('/admin/protocols/'.$protocol->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('protocol.phases.0.supplements.0.dosage_mode', 'manual')->where('protocol.customer_settings.weight_kg', 600));
        $payload['phases'][0]['id'] = $protocol->phases->first()->id;
        foreach ($protocol->phases->first()->supplements as $i => $item) {
            $payload['phases'][0]['supplements'][$i]['id'] = $item->id;
        }
        $payload['customer_settings']['weight_kg'] = 450;
        $payload['customer_settings']['use_target_weight'] = false;
        $this->put('/admin/protocols/'.$protocol->id, $payload)->assertSessionHasNoErrors();
        $this->assertSame(['82 g persoonlijk', '15 g', '150 g'], $protocol->fresh()->phases->first()->supplements->pluck('dosage')->all());
    }

    public function test_all_current_products_and_individual_overrides_reach_customer_output(): void
    {
        $nutrition = app(ProtocolNutrition::class);
        $answers = $nutrition->answers($this->horse);
        $feeds = $nutrition->currentFeeds($answers);
        $this->assertCount(7, $feeds);
        $this->assertCount(7, array_unique(array_column($feeds, 'id')));
        $this->assertStringNotContainsString('Historisch', json_encode($feeds));
        $this->assertSame('10 g · per dag', $feeds[2]['dosage']);
        $this->payload['customer_settings']['feed_overrides'] = array_map(fn ($feed) => ['id' => $feed['id'], 'status' => 'continue', 'note' => 'Persoonlijk: '.$feed['name']], $feeds);
        $this->payload['customer_settings']['feed_overrides'][1]['status'] = 'stop';
        $this->post('/admin/protocols', $this->payload)->assertSessionHasNoErrors();
        $data = app(HorseDashboard::class)->protocol(Protocol::firstOrFail(), CarbonImmutable::parse('2026-09-01'), answers: $answers);
        $this->assertCount(7, $data['nutrition']['feeds']);
        $this->assertSame('stop', $data['nutrition']['feeds'][1]['status']);
        $this->assertSame('Persoonlijk: Wortels', $data['nutrition']['feeds'][3]['note']);
        $answers['supplementen-nu'] = 'Nee';
        $answers['bijvoeding-nu'] = 'Nee';
        $this->assertCount(5, $nutrition->currentFeeds($answers));
        $answers['balancer'][] = ['product' => 'Compleet', 'merk' => 'Balancer', 'hoeveelheid' => '100 g'];
        $duplicateFeeds = $nutrition->currentFeeds($answers);
        $this->assertCount(6, array_unique(array_column($duplicateFeeds, 'id')));
    }

    public function test_preview_uses_unsaved_values_and_weeks_without_any_database_writes(): void
    {
        $this->post('/admin/protocols', $this->payload)->assertSessionHasNoErrors();
        $stored = Protocol::firstOrFail();
        $payload = $this->payload;
        $payload['title'] = 'Nog niet opgeslagen';
        $payload['analysis']['summary'] = 'Nieuwe persoonlijke analyse.';
        $payload['phases'][0]['id'] = $stored->phases->first()->id;
        foreach ($stored->phases->first()->supplements as $i => $item) {
            $payload['phases'][0]['supplements'][$i]['id'] = $item->id;
        }
        $payload['phases'][0]['supplements'][0]['dosage_mode'] = 'manual';
        $payload['phases'][0]['supplements'][0]['dosage'] = '77 g';
        DB::enableQueryLog();
        $preview = $this->postJson('/admin/protocols/'.$stored->id.'/preview', $payload)->assertOk();
        $preview->assertJsonPath('protocol.title', 'Nog niet opgeslagen')->assertJsonPath('protocol.phases.0.supplements.0.dosage', '77 g')
            ->assertJsonPath('protocol.phases.0.supplements.0.instructions', 'Bereiden met water.')
            ->assertJsonPath('protocol.phases.0.supplements.0.frequencyLabel', '4× per week')
            ->assertJsonPath('protocol.phases.0.supplements.0.weekNumbers', [1, 3])
            ->assertJsonPath('protocol.analysis.summary', 'Nieuwe persoonlijke analyse.')->assertJsonCount(3, 'protocol.today.items');
        $writes = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\\s*(insert|update|delete|alter|create)\\b/i', $q['query']));
        DB::disableQueryLog();
        $this->assertCount(0, $writes, $writes->toJson());
        $this->postJson('/admin/protocols/'.$stored->id.'/preview', $payload + ['preview_week' => 2])->assertOk()->assertJsonCount(0, 'protocol.today.items');
        $this->assertSame('Protocol R', $stored->fresh()->title);
        $this->assertNull($stored->fresh()->published_at);
    }

    public function test_preview_matches_published_customer_content_with_overlapping_phases_and_advice(): void
    {
        $template = ProtocolTemplate::findOrFail($this->payload['protocol_template_id']);
        $phase = $template->phases()->create(['name' => 'Overlap', 'order' => 1, 'required' => false, 'start_after_previous_phase_weeks' => 1]);
        foreach ([1, 2] as $n) {
            $phase->weeks()->create(['number' => $n]);
        }
        $item = $phase->supplements()->create(['name' => 'Overlap kruid', 'supplement_type' => 'kruid', 'dosis_type' => 'per_600_kg', 'dosis' => 5, 'unit' => 'g']);
        $payload = $this->payload;
        $payload['phases'][] = ['protocol_template_phase_id' => $phase->id, 'client_key' => 'two', 'week_count' => 2, 'start_after_previous_phase_weeks' => 1,
            'supplements' => [['supplement_id' => $item->id, 'dosage_mode' => 'automatic', 'aantal_per_week' => 4, 'week_numbers' => [2], 'instructions' => 'Alleen in de tweede faseweek.']]];
        $management = ManagementAdvies::create(['title' => 'Weide', 'description' => 'Basisadvies']);
        $movement = BewegingAdvies::create(['title' => 'Stappen', 'description' => 'Rustig opbouwen.']);
        $payload['management_advies_ids'] = [$management->id];
        $payload['beweging_advies_ids'] = [$movement->id];
        $payload['customer_settings']['management'] = [['id' => $management->id, 'category' => 'environment', 'action' => 'avoid', 'instruction' => 'Persoonlijke instructie', 'note' => 'Therapeutnotitie']];
        $preview = $this->postJson('/admin/protocols/preview', $payload + ['preview_week' => 3])->assertOk()
            ->assertJsonPath('protocol.phases.1.weekStart', 2)->assertJsonPath('protocol.phases.1.weekEnd', 3)
            ->assertJsonPath('protocol.phases.1.supplements.0.weekNumbers', [3])->assertJsonPath('protocol.phases.1.supplements.0.dosage', '5 g')
            ->assertJsonPath('protocol.management.0.items.0.note', 'Therapeutnotitie')->assertJsonPath('protocol.movement.0.title', 'Stappen');
        $payload['published'] = true;
        $this->post('/admin/protocols', $payload)->assertSessionHasNoErrors();
        $protocol = Protocol::firstOrFail();
        $customer = app(HorseDashboard::class)->protocol($protocol, CarbonImmutable::parse('2026-09-15', 'Europe/Amsterdam'), answers: app(ProtocolNutrition::class)->answers($this->horse));
        $previewNutrition = $preview->json('protocol.nutrition');
        $customerNutrition = $customer['nutrition'] + ['hayLibraryItem' => null, 'waterLibraryItem' => null];
        // Saving creates snapshot IDs; authored content and calculated values must match.
        foreach ([$previewNutrition['advice'], $customerNutrition['advice']] as $advice) {
            $this->assertSame('Voedingsadvies op maat', $advice[0]['title']);
        }
        unset($previewNutrition['advice'][0]['id'], $customerNutrition['advice'][0]['id']);
        $this->assertEquals($previewNutrition, $customerNutrition);
        $this->assertEquals($preview->json('protocol.analysis'), $customer['analysis']);
        $this->assertSame(array_column($preview->json('protocol.today.items'), 'name'), array_column($customer['today']['items'], 'name'));
        foreach ($customer['phases'] as $i => $customerPhase) {
            $this->assertSame($preview->json("protocol.phases.$i.weekStart"), $customerPhase['weekStart']);
            $this->assertSame($preview->json("protocol.phases.$i.weekEnd"), $customerPhase['weekEnd']);
            $this->assertSame(array_column($preview->json("protocol.phases.$i.supplements"), 'dosage'), array_column($customerPhase['supplements'], 'dosage'));
        }
    }

    public function test_rounding_boundaries_are_applied_by_the_real_save_path_for_multiple_horses(): void
    {
        foreach ([300, 535, 600, 750] as $weight) {
            $horse = Horse::create(['name' => 'Weight '.$weight, 'owner_id' => $this->horse->owner_id, 'weight_kg' => $weight, 'status' => 'active']);
            $payload = $this->payload;
            $payload['horse_id'] = $horse->id;
            $this->post('/admin/protocols', $payload)->assertSessionHasNoErrors();
            $expected = [300 => ['50 g', '7.5 g', '100 g'], 535 => ['90 g', '15 g', '180 g'], 600 => ['100 g', '15 g', '200 g'], 750 => ['125 g', '20 g', '250 g']][$weight];
            $this->assertSame($expected, $horse->protocols()->first()->phases->first()->supplements->pluck('dosage')->all());
        }
    }

    public function test_preview_requires_admin_authentication(): void
    {
        auth('admin')->logout();
        $this->postJson('/admin/protocols/preview', $this->payload)->assertUnauthorized();
    }

    public function test_preview_and_save_reject_an_enabled_missing_target(): void
    {
        $payload = $this->payload;
        $payload['customer_settings']['use_target_weight'] = true;
        $this->postJson('/admin/protocols/preview', $payload)->assertUnprocessable()->assertJsonValidationErrors('customer_settings.target_weight_kg');
        $this->postJson('/admin/protocols', $payload)->assertUnprocessable()->assertJsonValidationErrors('customer_settings.target_weight_kg');
    }
}
