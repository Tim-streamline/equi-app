<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\BewegingAdvies;
use App\Models\Horse;
use App\Models\IntakeResponse;
use App\Models\LibraryItem;
use App\Models\ManagementAdvies;
use App\Models\Plan;
use App\Models\Protocol;
use App\Models\ProtocolTemplate;
use App\Models\SeasonalTip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HorseDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Horse $horse;

    private Protocol $protocol;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 8, 28)->setTime(12, 0));
        $this->owner = User::factory()->create(['name' => 'Test Owner']);
        $this->horse = Horse::create(['owner_id' => $this->owner->id, 'name' => 'Test Horse', 'weight_kg' => 600, 'status' => 'active']);
        $template = ProtocolTemplate::create(['name' => 'Test protocol']);
        $this->protocol = $this->horse->protocols()->create([
            'protocol_template_id' => $template->id, 'title' => 'Personal protocol',
            'status' => 'active', 'published_at' => now()->subDay(), 'started_at' => '2026-08-15',
            'total_weeks' => 4, 'current_week' => 1,
        ]);
        foreach ([[1, 2], [3, 4]] as $index => [$start, $end]) {
            $definition = $template->phases()->create(['name' => 'Phase '.($index + 1), 'order' => $index]);
            $phase = $this->protocol->phases()->create([
                'protocol_template_phase_id' => $definition->id, 'title' => $definition->name,
                'state' => 'upcoming', 'description' => 'Personal phase purpose', 'order' => $index, 'week_start' => $start, 'week_end' => $end,
            ]);
            $supplement = $phase->supplements()->create(['name' => 'Herb '.($index + 1), 'dosage' => '20 g', 'description' => 'Personal function']);
            foreach (range($start, $end) as $local => $global) {
                $week = $phase->weeks()->create(['number' => $local + 1, 'protocol_week_number' => $global]);
                $supplement->weeks()->create(['protocol_phase_week_id' => $week->id]);
            }
        }
        $ownerId = $this->owner->id;
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($ownerId)
        {
            public function __construct(private string $userId) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->userId);

                return $next($request);
            }
        });
    }

    private function dayUrl(string $date): string
    {
        return '/api/horses/'.$this->horse->id.'/protocol-day?'.http_build_query([
            'protocol_id' => $this->protocol->id, 'date' => $date, 'timezone' => 'Europe/Amsterdam',
        ]);
    }

    public function test_historical_days_use_their_phase_and_update_calendar_after_checking_and_unchecking(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 3));
        $date = '2026-08-27';
        $day = $this->getJson($this->dayUrl($date))->assertOk()->assertJsonPath('items.0.name', 'Herb 1')
            ->assertJsonPath('editable', true)->assertJsonPath('state', 'missed');
        $itemId = $day->json('items.0.id');
        foreach ([true => 'complete', false => 'missed'] as $done => $state) {
            $this->postJson($this->dayUrl($date), ['item_id' => $itemId, 'done' => (bool) $done])
                ->assertOk()->assertJsonPath('items.0.done', (bool) $done)->assertJsonPath('state', $state);
            $calendar = $this->getJson('/api/horses/'.$this->horse->id.'/dashboard?month=2026-08')->assertOk()->json('protocol.calendar.cells');
            $this->assertSame($state, collect($calendar)->filter()->firstWhere('date', $date)['state']);
        }
        $futureItem = $this->protocol->phases()->reorder('order', 'desc')->first()->supplements()->first()->id;
        $this->postJson($this->dayUrl($date), ['item_id' => $futureItem, 'done' => true])->assertUnprocessable();
    }

    public function test_historical_correction_window_is_inclusive_and_older_days_are_read_only(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 1));
        $itemId = $this->getJson($this->dayUrl('2026-08-18'))->assertOk()->assertJsonPath('editable', true)->json('items.0.id');
        $this->postJson($this->dayUrl('2026-08-18'), ['item_id' => $itemId, 'done' => true])->assertOk();
        $this->postJson($this->dayUrl('2026-08-18'), ['item_id' => $itemId, 'done' => false])->assertOk();
        $this->getJson($this->dayUrl('2026-08-17'))->assertOk()->assertJsonPath('editable', false);
        foreach ([true, false] as $done) {
            $this->postJson($this->dayUrl('2026-08-17'), ['item_id' => $itemId, 'done' => $done])->assertUnprocessable();
        }
        foreach (['2026-09-01', '2026-09-02'] as $date) {
            $this->getJson($this->dayUrl($date))->assertUnprocessable();
            $this->postJson($this->dayUrl($date), ['item_id' => $itemId, 'done' => true])->assertUnprocessable();
        }
        $this->getJson($this->dayUrl('2026-08-14'))->assertNotFound();
    }

    public function test_preserved_items_and_checkmarks_survive_removal_and_support_corrections(): void
    {
        $item = $this->protocol->phases()->first()->supplements()->first();
        $item->intakes()->create(['horse_id' => $this->horse->id, 'date' => '2026-08-27', 'dosage' => '20 g', 'done' => true]);
        $this->getJson($this->dayUrl('2026-08-27'))->assertOk()->assertJsonPath('items.0.done', true);
        $item->delete();
        $this->getJson($this->dayUrl('2026-08-27'))->assertOk()->assertJsonPath('items.0.name', 'Herb 1')->assertJsonPath('items.0.dosage', '20 g')->assertJsonPath('items.0.done', true);
        $this->postJson($this->dayUrl('2026-08-27'), ['item_id' => $item->id, 'done' => false])->assertOk()->assertJsonPath('state', 'missed');
        $this->postJson($this->dayUrl('2026-08-27'), ['item_id' => $item->id, 'done' => true])->assertOk()->assertJsonPath('state', 'complete');
    }

    public function test_history_is_owner_scoped_and_requires_a_published_protocol(): void
    {
        $other = Horse::create(['owner_id' => User::factory()->create()->id, 'name' => 'Other', 'status' => 'active']);
        $url = str_replace($this->horse->id, $other->id, $this->dayUrl('2026-08-27'));
        $this->getJson($url)->assertNotFound();
        $this->postJson($url, ['item_id' => (string) \Illuminate\Support\Str::uuid(), 'done' => true])->assertNotFound();
        $this->protocol->update(['published_at' => null]);
        $this->getJson($this->dayUrl('2026-08-27'))->assertNotFound();
    }

    public function test_sync_upload_cannot_bypass_date_or_plan_limits_and_updates_history(): void
    {
        $item = $this->protocol->phases()->first()->supplements()->first();
        $id = (string) \Illuminate\Support\Str::uuid();
        $operation = ['op' => 'PUT', 'type' => 'protocol_supplement_intakes', 'id' => $id, 'data' => [
            'protocol_phase_supplement_id' => $item->id, 'horse_id' => $this->horse->id,
            'date' => '2026-08-27', 'done' => true, 'dosage' => '20 g',
        ]];
        $this->getJson($this->dayUrl('2026-08-27'))->assertOk();
        $this->postJson('/api/sync/upload', ['operations' => [$operation]])->assertOk();
        $this->getJson($this->dayUrl('2026-08-27'))->assertJsonPath('state', 'complete');
        foreach (['2026-08-29', '2026-08-13'] as $date) {
            $bad = $operation;
            $bad['id'] = (string) \Illuminate\Support\Str::uuid();
            $bad['data']['date'] = $date;
            $this->postJson('/api/sync/upload', ['operations' => [$bad]])->assertUnprocessable();
        }
        $this->postJson('/api/sync/upload', ['operations' => [['op' => 'PATCH', 'type' => 'protocol_supplement_intakes', 'id' => $id, 'data' => ['done' => false]]]])->assertOk();
        $this->getJson($this->dayUrl('2026-08-27'))->assertJsonPath('state', 'missed');
    }

    public function test_sync_date_limits_use_the_same_device_timezone_as_the_dashboard(): void
    {
        $this->travelTo(now()->setDate(2026, 8, 27)->setTime(12, 30));
        $item = $this->protocol->phases()->first()->supplements()->first();
        $this->postJson('/api/sync/upload', ['timezone' => 'Pacific/Kiritimati', 'operations' => [[
            'op' => 'PUT', 'type' => 'protocol_supplement_intakes', 'id' => (string) \Illuminate\Support\Str::uuid(),
            'data' => ['protocol_phase_supplement_id' => $item->id, 'horse_id' => $this->horse->id, 'date' => '2026-08-28', 'done' => true],
        ]]])->assertOk();
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard?timezone=Pacific/Kiritimati')
            ->assertOk()->assertJsonPath('date', '2026-08-28')->assertJsonPath('protocol.today.done', 1);
    }

    public function test_phase_labels_use_duration_and_each_phases_own_week(): void
    {
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()
            ->assertJsonPath('protocol.phases.0.durationLabel', 'Duur: 2 weken')
            ->assertJsonPath('protocol.phases.0.statusLabel', 'Actief · week 2 van 2');
        $phase = $this->protocol->phases()->reorder('order', 'desc')->first();
        foreach ($phase->weeks as $week) {
            $week->update(['protocol_week_number' => $week->protocol_week_number - 1]);
        }
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()
            ->assertJsonPath('protocol.phases.0.statusLabel', 'Actief · week 2 van 2')
            ->assertJsonPath('protocol.phases.1.statusLabel', 'Actief · week 1 van 2');
    }

    public function test_home_returns_up_to_four_published_recommendations_with_access_metadata(): void
    {
        $items = collect(range(1, 5))->map(fn ($i) => LibraryItem::create([
            'title' => 'Lesson '.$i, 'slug' => 'home-lesson-'.$i, 'format' => $i === 1 ? 'audio' : 'article',
            'published_at' => now()->subDay(), 'is_plus' => true, 'credit_cost' => 0,
        ]));
        LibraryItem::create(['title' => 'Draft', 'slug' => 'draft', 'format' => 'article']);
        $url = '/api/horses/'.$this->horse->id.'/dashboard';
        $first = $this->getJson($url)->assertOk()->assertJsonCount(4, 'recommendations');
        $this->assertCount(4, array_unique(array_column($first->json('recommendations'), 'id')));
        foreach ($first->json('recommendations') as $item) {
            $this->assertTrue($items->contains('id', $item['id']));
            $this->assertTrue($item['isPlus']);
        }
        $this->assertSame($first->json('recommendations'), $this->getJson($url)->json('recommendations'));
        $items->skip(2)->each(fn ($item) => $item->update(['published_at' => now()->addDay()]));
        $this->getJson($url)->assertJsonCount(2, 'recommendations');
        $this->protocol->update(['status' => 'draft']);
        $this->getJson($url)->assertJsonPath('variant', 'basic')->assertJsonCount(2, 'recommendations');
    }

    public function test_backend_calculates_progress_next_phase_and_calendar_from_dates(): void
    {
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard?timezone=Europe/Amsterdam')
            ->assertOk()->assertJsonPath('protocol.currentWeek', 2)
            ->assertJsonPath('protocol.currentDay', 14)->assertJsonPath('protocol.progressPercent', 50)
            ->assertJsonPath('protocol.phases.0.state', 'active')
            ->assertJsonPath('protocol.phases.1.state', 'preview')
            ->assertJsonPath('protocol.phases.1.accessible', true)
            ->assertJsonPath('protocol.notifications.1.type', 'next_phase')
            ->assertJsonPath('protocol.notifications.1.items.0.name', 'Herb 2')
            ->assertJsonPath('protocol.today.total', 1);
    }

    public function test_locked_phase_content_is_redacted_from_every_dashboard_list_until_exact_boundary(): void
    {
        $this->protocol->update(['published_at' => '2026-08-15 00:00:00']);
        $this->travelTo(now()->setDate(2026, 8, 21)->setTime(23, 59, 59));
        $url = '/api/horses/'.$this->horse->id.'/dashboard?timezone=UTC';
        $this->getJson($url)->assertOk()
            ->assertJsonPath('protocol.phases.1.state', 'locked')
            ->assertJsonPath('protocol.phases.1.accessible', false)
            ->assertJsonPath('protocol.phases.1.description', null)
            ->assertJsonCount(0, 'protocol.phases.1.supplements')
            ->assertJsonMissing(['name' => 'Herb 2']);
        $this->travelTo(now()->setDate(2026, 8, 22)->setTime(0, 0));
        $this->getJson($url)->assertOk()
            ->assertJsonPath('protocol.phases.1.state', 'preview')
            ->assertJsonPath('protocol.phases.1.statusLabel', 'Start volgende week')
            ->assertJsonPath('protocol.phases.1.description', 'Personal phase purpose')
            ->assertJsonPath('protocol.phases.1.supplements.0.dosage', '20 g')
            ->assertJsonCount(2, 'protocol.orderItems');
    }

    public function test_other_owners_and_unpublished_protocols_are_not_exposed(): void
    {
        $other = Horse::create(['owner_id' => User::factory()->create()->id, 'name' => 'Other', 'status' => 'active']);
        $this->getJson('/api/horses/'.$other->id.'/dashboard')->assertNotFound();
        $this->protocol->update(['published_at' => null]);
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()->assertJsonPath('protocol', null);
    }

    public function test_nutrition_uses_target_not_current_weight_and_horse_intake(): void
    {
        $this->protocol->update(['customer_settings' => ['target_weight_kg' => 400, 'sugar' => '<6%', 'protein' => '7–10%']]);
        $response = IntakeResponse::create(['user_id' => $this->owner->id, 'horse_id' => $this->horse->id, 'status' => 'submitted', 'submitted_at' => now()]);
        foreach (['huidige-bijvoeding' => [['merk' => 'Metazoa', 'product' => 'Fit', 'hoeveelheid' => '100 g'], ['merk' => 'Other', 'product' => 'Mix']], 'water-type' => ['Grondwater / bronwater']] as $field => $value) {
            $response->answers()->create(['section_id' => 'voeding', 'field_id' => $field, 'value' => json_encode($value)]);
        }
        $data = $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk();
        $data->assertJsonPath('protocol.nutrition.roughage.minimumKg', 8)
            ->assertJsonPath('protocol.nutrition.roughage.maximumKg', 12)
            ->assertJsonPath('protocol.nutrition.roughage.sugar', '<6%')
            ->assertJsonPath('protocol.nutrition.feeds.0.status', 'continue')
            ->assertJsonPath('protocol.nutrition.feeds.1.status', 'stop')
            ->assertJsonPath('protocol.nutrition.water.needsAnalysis', true);
        $key = $data->json('protocol.nutrition.feeds.1.id');
        $this->protocol->update(['customer_settings' => ['feed_overrides' => [['id' => $key, 'status' => 'continue', 'note' => 'Therapist decision']]]]);
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()
            ->assertJsonPath('protocol.nutrition.roughage.minimumKg', null)
            ->assertJsonPath('protocol.nutrition.feeds.1.status', 'continue')
            ->assertJsonPath('protocol.nutrition.feeds.1.note', 'Therapist decision');
    }

    public function test_weekly_update_persists_and_clears_only_its_protocol_reminder(): void
    {
        $url = '/api/horses/'.$this->horse->id;
        $this->postJson($url.'/weekly-update', ['protocol_id' => $this->protocol->id, 'note' => 'Going well', 'mood' => 4])->assertOk();
        $this->getJson($url.'/dashboard')->assertOk()->assertJsonCount(1, 'protocol.notifications')
            ->assertJsonPath('protocol.notifications.0.type', 'next_phase');
        $this->assertDatabaseHas('protocol_weekly_updates', ['protocol_id' => $this->protocol->id, 'week_number' => 2, 'note' => 'Going well']);
    }

    public function test_future_and_finished_protocols_do_not_offer_daily_actions_or_reminders(): void
    {
        foreach (['2026-09-15' => 0, '2026-07-01' => 100] as $start => $progress) {
            $this->protocol->update(['started_at' => $start]);
            $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()
                ->assertJsonPath('protocol.progressPercent', $progress)
                ->assertJsonCount(0, 'protocol.today.items')->assertJsonCount(0, 'protocol.notifications');
        }
    }

    public function test_management_hides_empty_groups_and_internal_metadata_and_limits_analysis(): void
    {
        $source = ManagementAdvies::create(['title' => 'Gebitscontrole', 'description' => 'Instructie']);
        $this->protocol->managementAdviezen()->create(['management_advies_id' => $source->id, 'title' => $source->title, 'description' => $source->description]);
        $this->protocol->update(['customer_settings' => ['management' => [['id' => $source->id, 'category' => 'care', 'action' => 'avoid', 'note' => 'Persoonlijk', 'frequency' => 'Jaarlijks']]]]);
        $this->protocol->analysis()->create([
            'cause' => 'Original internal notes must not be used as a customer summary.',
            'summary' => 'Personal explanation',
            'focus_points' => collect(range(1, 4))->map(fn ($i) => ['title' => 'Personal focus '.$i, 'body' => 'Personal goal.'])->all(),
            'observations' => ['Personal observation.'],
        ]);
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()
            ->assertJsonCount(1, 'protocol.management')->assertJsonPath('protocol.management.0.id', 'care')
            ->assertJsonPath('protocol.management.0.items.0.action', 'avoid')->assertJsonPath('protocol.management.0.items.0.note', 'Persoonlijk')
            ->assertJsonPath('protocol.analysis.summary', 'Personal explanation')->assertJsonCount(4, 'protocol.analysis.priorities')
            ->assertJsonPath('protocol.analysis.priorities.3.title', 'Personal focus 4')
            ->assertJsonPath('protocol.analysis.observations.0', 'Personal observation.')
            ->assertJsonMissingPath('protocol.analysis.priorities.0.icon_key');
    }

    public function test_legacy_analysis_is_not_automatically_rewritten_or_presented_as_compact_analysis(): void
    {
        $analysis = $this->protocol->analysis()->create(['cause' => 'Legacy long analysis.']);
        $analysis->advice()->create(['title' => 'Voeding', 'body' => 'Legacy advice.', 'icon_key' => 'leaf', 'order' => 0]);
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()->assertJsonPath('protocol.analysis', null);
        $this->assertSame('Legacy long analysis.', $analysis->fresh()->cause);
        $this->assertSame(1, $analysis->advice()->count());
    }

    public function test_care_sources_only_include_selected_advice_from_this_horses_current_publication(): void
    {
        $environment = ManagementAdvies::create(['title' => 'Voerplekken', 'description' => 'Verdeel de voerplekken.']);
        $physical = ManagementAdvies::create(['title' => 'Hoefverzorging', 'description' => 'Persoonlijke hoefverzorging.']);
        $research = ManagementAdvies::create(['title' => 'Mestonderzoek', 'description' => 'Laat mest onderzoeken.']);
        foreach ([$environment, $physical, $research] as $source) {
            $this->protocol->managementAdviezen()->create(['management_advies_id' => $source->id, 'title' => $source->title, 'description' => $source->description]);
        }
        $movement = BewegingAdvies::create(['title' => 'Rustig stappen', 'description' => 'Tweemaal per dag 10 minuten. Niet draven tijdens herstel.']);
        $selectedMovement = $this->protocol->bewegingAdviezen()->create(['beweging_advies_id' => $movement->id, 'title' => $movement->title, 'description' => $movement->description]);
        $unused = ManagementAdvies::create(['title' => 'Niet geselecteerd', 'description' => 'Alleen in de catalogus.']);
        BewegingAdvies::create(['title' => 'Niet geselecteerde beweging', 'description' => 'Alleen in de catalogus.']);
        $this->protocol->update(['customer_settings' => ['management' => [
            ['id' => $unused->id, 'category' => 'care', 'action' => 'do', 'instruction' => 'Achtergebleven instelling voor uitgeschakeld advies.'],
            ['id' => $environment->id, 'category' => 'environment', 'action' => 'avoid', 'instruction' => 'Geen voerplekken op het gras.', 'note' => 'Tot de evaluatie.', 'frequency' => 'Tijdens dit protocol'],
        ]]]);

        $otherHorse = Horse::create(['owner_id' => $this->owner->id, 'name' => 'Tweede paard', 'status' => 'active']);
        foreach ([
            ['horse_id' => $otherHorse->id],
            ['published_at' => null],
            ['published_at' => now()->addDay()],
            ['published_at' => now()->subDays(2)],
            ['status' => 'paused'],
        ] as $attributes) {
            $excluded = $this->protocol->replicate()->fill($attributes);
            $excluded->save();
            $excluded->managementAdviezen()->create(['management_advies_id' => $unused->id, 'title' => 'Advies buiten actief protocol', 'description' => 'Mag niet in Zorg verschijnen.']);
            $excluded->bewegingAdviezen()->create(['beweging_advies_id' => $movement->id, 'title' => 'Beweging buiten actief protocol', 'description' => 'Mag niet in Zorg verschijnen.']);
        }

        $response = $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()
            ->assertJsonPath('protocol.id', $this->protocol->id)
            ->assertJsonCount(3, 'protocol.management')
            ->assertJsonPath('protocol.management.0.id', 'environment')
            ->assertJsonCount(1, 'protocol.management.0.items')
            ->assertJsonPath('protocol.management.0.items.0.description', 'Geen voerplekken op het gras.')
            ->assertJsonPath('protocol.management.0.items.0.action', 'avoid')
            ->assertJsonPath('protocol.management.0.items.0.note', 'Tot de evaluatie.')
            ->assertJsonPath('protocol.management.0.items.0.frequency', 'Tijdens dit protocol')
            ->assertJsonPath('protocol.management.1.id', 'care')
            ->assertJsonPath('protocol.management.1.items.0.title', 'Hoefverzorging')
            ->assertJsonPath('protocol.management.2.id', 'monitoring')
            ->assertJsonPath('protocol.management.2.items.0.title', 'Mestonderzoek')
            ->assertJsonCount(1, 'protocol.movement')
            ->assertJsonPath('protocol.movement.0.description', $movement->description);
        $response->assertJsonMissing(['title' => 'Niet geselecteerd'])
            ->assertJsonMissing(['title' => 'Advies buiten actief protocol'])
            ->assertJsonMissing(['title' => 'Beweging buiten actief protocol']);

        // Removing a selection must immediately remove it from the next dashboard response.
        $selectedMovement->delete();
        $this->protocol->managementAdviezen()->delete();
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()
            ->assertJsonCount(0, 'protocol.management')->assertJsonCount(0, 'protocol.movement');
    }

    public function test_home_uses_current_account_credits_unlocks_season_and_subscription(): void
    {
        $this->protocol->update(['published_at' => null]);
        $item = LibraryItem::create(['slug' => 'hooianalyse', 'title' => 'Hay analysis', 'format' => 'article', 'published_at' => now()->subDay()]);
        DB::table('library_credit_balances')->insert(['user_id' => $this->owner->id, 'balance' => 9]);
        DB::table('library_unlocks')->insert(['user_id' => $this->owner->id, 'item_id' => $item->id]);
        SeasonalTip::create(['month' => 'augustus', 'month_order' => 8, 'body' => 'Managed seasonal text', 'active' => true, 'cta_item_id' => $item->id]);
        SeasonalTip::create(['month' => 'juli', 'month_order' => 7, 'body' => 'Outdated text', 'active' => true]);
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()->assertJsonPath('variant', 'basic')
            ->assertJsonPath('credits', 9)->assertJsonPath('showPlusUpsell', true)
            ->assertJsonPath('seasonalTip.body', 'Managed seasonal text')->assertJsonPath('recommendations.0.unlocked', true);
        $plan = Plan::create(['slug' => 'plus', 'label' => 'Plus', 'name' => 'Plus', 'price_cents' => 100, 'currency' => 'EUR', 'interval' => 'month']);
        $plan->benefits()->create(['label' => 'Persoonlijk protocol', 'order' => 1]);
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()
            ->assertJsonPath('plusOffer.priceLabel', '€ 1,00')->assertJsonPath('plusOffer.benefits.0', 'Persoonlijk protocol');
        $this->owner->subscriptions()->create(['plan_id' => $plan->id, 'status' => 'active', 'price_cents' => 100, 'currency' => 'EUR', 'interval' => 'month']);
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()->assertJsonPath('variant', 'plus')
            ->assertJsonPath('protocol', null)->assertJsonPath('showPlusUpsell', false);
    }

    public function test_day_states_follow_protocol_schedule_and_do_not_mix_horses(): void
    {
        $supplement = $this->protocol->phases()->first()->supplements()->first();
        $this->horse->supplementIntakes()->create(['protocol_phase_supplement_id' => $supplement->id, 'date' => '2026-08-28', 'done' => true]);
        $result = $this->getJson('/api/horses/'.$this->horse->id.'/dashboard?month=2026-08')->assertOk()
            ->assertJsonPath('protocol.today.percentage', 100);
        $cells = collect($result->json('protocol.calendar.cells'))->filter()->keyBy('date');
        $this->assertSame('complete', $cells['2026-08-28']['state']);
        $this->assertSame('default', $cells['2026-08-14']['state']);
        $this->assertSame('missed', $cells['2026-08-27']['state']);
        $this->assertSame('default', $cells['2026-08-29']['state']);
    }

    public function test_invalid_calendar_parameters_and_missing_authentication_are_rejected(): void
    {
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard?month=not-a-month')->assertUnprocessable();
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard?timezone=not-a-zone')->assertUnprocessable();
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new AuthenticatePowerSyncJwt);
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertUnauthorized();
    }

    public function test_seasonal_tip_exposes_stable_identity_managed_title_intro_and_content_link(): void
    {
        $item = LibraryItem::create(['slug' => 'season-tip-article', 'title' => 'Gekoppeld artikel', 'format' => 'article', 'published_at' => now()->subDay()]);
        $tip = SeasonalTip::create(['title' => 'Persoonlijke seizoenstip', 'month' => 'nazomer', 'month_order' => 8,
            'body' => str_repeat('Praktische introductie uit het beheer. ', 12), 'active' => true, 'cta_item_id' => $item->id]);
        $response = $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()
            ->assertJsonPath('seasonalTip.id', $tip->id)
            ->assertJsonPath('seasonalTip.title', $tip->title)
            ->assertJsonPath('seasonalTip.month', 'nazomer')
            ->assertJsonPath('seasonalTip.item.id', $item->id);
        $this->assertLessThanOrEqual(183, mb_strlen($response->json('seasonalTip.intro')));
        $this->assertStringStartsWith('Praktische introductie uit het beheer.', $response->json('seasonalTip.intro'));
        $tip->update(['title' => 'Bewerkte titel']);
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()
            ->assertJsonPath('seasonalTip.id', $tip->id)->assertJsonPath('seasonalTip.title', 'Bewerkte titel');
    }

    public function test_dated_seasonal_content_takes_precedence_over_the_monthly_fallback(): void
    {
        SeasonalTip::create(['month' => 'augustus', 'month_order' => 8, 'body' => 'Monthly fallback', 'active' => true]);
        SeasonalTip::create(['title' => 'Current seasonal title', 'month' => 'nazomer', 'month_order' => 8,
            'body' => 'Current dated tip', 'active' => true, 'active_from' => '2026-08-25', 'active_to' => '2026-09-05']);
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()
            ->assertJsonPath('seasonalTip.title', 'Current seasonal title')->assertJsonPath('seasonalTip.body', 'Current dated tip');
    }
}
