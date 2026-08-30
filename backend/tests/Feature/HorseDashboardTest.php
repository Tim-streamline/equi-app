<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
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

    public function test_backend_calculates_progress_next_phase_and_calendar_from_dates(): void
    {
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard?timezone=Europe/Amsterdam')
            ->assertOk()->assertJsonPath('protocol.currentWeek', 2)
            ->assertJsonPath('protocol.currentDay', 14)->assertJsonPath('protocol.progressPercent', 50)
            ->assertJsonPath('protocol.phases.0.state', 'active')
            ->assertJsonPath('protocol.phases.1.state', 'upcoming')
            ->assertJsonPath('protocol.notifications.1.type', 'next_phase')
            ->assertJsonPath('protocol.notifications.1.items.0.name', 'Herb 2')
            ->assertJsonPath('protocol.today.total', 1);
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
        foreach (['2026-09-01' => 0, '2026-07-01' => 100] as $start => $progress) {
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
        $analysis = $this->protocol->analysis()->create(['cause' => 'Personal explanation']);
        foreach (range(1, 7) as $i) {
            $analysis->advice()->create(['title' => 'Priority '.$i, 'body' => 'Explanation', 'icon_key' => 'leaf', 'order' => $i]);
        }
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()
            ->assertJsonCount(1, 'protocol.management')->assertJsonPath('protocol.management.0.id', 'care')
            ->assertJsonPath('protocol.management.0.items.0.action', 'avoid')->assertJsonPath('protocol.management.0.items.0.note', 'Persoonlijk')
            ->assertJsonPath('protocol.analysis.summary', 'Personal explanation')->assertJsonCount(5, 'protocol.analysis.priorities')
            ->assertJsonMissingPath('protocol.analysis.priorities.0.icon_key');
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

    public function test_dated_seasonal_content_takes_precedence_over_the_monthly_fallback(): void
    {
        SeasonalTip::create(['month' => 'augustus', 'month_order' => 8, 'body' => 'Monthly fallback', 'active' => true]);
        SeasonalTip::create(['title' => 'Current seasonal title', 'month' => 'nazomer', 'month_order' => 8,
            'body' => 'Current dated tip', 'active' => true, 'active_from' => '2026-08-25', 'active_to' => '2026-09-05']);
        $this->getJson('/api/horses/'.$this->horse->id.'/dashboard')->assertOk()
            ->assertJsonPath('seasonalTip.title', 'Current seasonal title')->assertJsonPath('seasonalTip.body', 'Current dated tip');
    }
}
