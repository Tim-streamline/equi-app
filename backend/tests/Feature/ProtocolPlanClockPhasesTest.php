<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\Horse;
use App\Models\Protocol;
use App\Models\ProtocolPhaseSupplement;
use App\Models\ProtocolSupplementIntake;
use App\Models\ProtocolTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phases on the plan clock, with the "Darm protocol" from the admin: phases run alongside each other, and
 * Darmrevalidatie follows Darm reset. The plan starts on Monday 7 September, so week n starts on
 * 7 September + 7(n - 1) days: week 3 on 21 September, week 5 on 5 October, week 9 on 2 November.
 *
 *   Maagondersteuning   week 1-8   Heemstwortel 1-6 (min 4, max 42), Lijnzaad 1-8 (min 5, max 56)
 *   Darm reset          week 3-4   Silsterk 3 (min 5, max 7)
 *   Darmrevalidatie     week 5-10  Salie 5-8 (min 4, max 28): follows Darm reset
 *   Mineralen aanvullen week 5-12  Zink 5-12 (min 4, max 56): runs alongside
 */
class ProtocolPlanClockPhasesTest extends TestCase
{
    use RefreshDatabase;

    private Horse $horse;

    private Protocol $protocol;

    /** @var array<string, ProtocolPhaseSupplement> */
    private array $herbs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->on('2026-09-07');
        $owner = User::factory()->create();
        $this->horse = Horse::create(['owner_id' => $owner->id, 'name' => 'Bella', 'weight_kg' => 600, 'status' => 'active']);
        $template = ProtocolTemplate::create(['name' => 'Darm protocol']);
        $this->protocol = $this->horse->protocols()->create([
            'protocol_template_id' => $template->id, 'title' => 'Plan', 'status' => 'active',
            'published_at' => now()->subWeek(), 'started_at' => '2026-09-07', 'total_weeks' => 12, 'current_week' => 1,
        ]);
        $plan = [
            'Maagondersteuning' => [1, 8, ['Heemstwortel' => [1, 6, 4, 42], 'Lijnzaad' => [1, 8, 5, 56]]],
            'Darm reset' => [3, 4, ['Silsterk' => [3, 3, 5, 7]]],
            'Darmrevalidatie' => [5, 10, ['Salie' => [5, 8, 4, 28]]],
            'Mineralen aanvullen' => [5, 12, ['Zink' => [5, 12, 4, 56]]],
        ];
        $order = 0;
        foreach ($plan as $title => [$first, $last, $products]) {
            $definition = $template->phases()->create(['name' => $title, 'order' => $order]);
            $phase = $this->protocol->phases()->create(['protocol_template_phase_id' => $definition->id, 'title' => $title,
                'state' => 'upcoming', 'order' => $order++, 'week_start' => $first, 'week_end' => $last]);
            $weeks = [];
            foreach (range($first, $last) as $local => $global) {
                $weeks[$global] = $phase->weeks()->create(['number' => $local + 1, 'protocol_week_number' => $global]);
            }
            foreach ($products as $name => [$from, $until, $min, $max]) {
                $herb = $phase->supplements()->create(['name' => $name, 'dosage' => '20 g', 'min_aantal_per_week' => $min, 'max_aantal_in_fase' => $max]);
                foreach (range($from, $until) as $number) {
                    $herb->weeks()->create(['protocol_phase_week_id' => $weeks[$number]->id]);
                }
                $this->herbs[$name] = $herb;
            }
        }
        $ownerId = $owner->id;
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($ownerId)
        {
            public function __construct(private string $userId) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->userId);
                $request->attributes->set('powersync_claims', ['protocol_doses' => 1, 'read_only_plans' => 1, 'read_only_doses' => 1]);

                return $next($request);
            }
        });
    }

    private function on(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' 12:00', 'Europe/Amsterdam'));
    }

    /** Ticks off every product on the days it is on the schedule (as the dashboard shows it), except $skip. */
    private function giveAsScheduled(string $from, string $until, array $skip = []): void
    {
        foreach (CarbonPeriod::create($from, $until) as $day) {
            $this->on($day->toDateString());
            foreach ($this->dashboard()['today']['items'] as $item) {
                if (! in_array($item['name'], $skip, true) && ! ($item['recordedOnly'] ?? false)) {
                    ProtocolSupplementIntake::firstOrCreate(['protocol_phase_supplement_id' => $item['id'], 'horse_id' => $this->horse->id,
                        'date' => $day->toDateString(), 'administration' => 1], ['dosage' => '20 g', 'done' => true]);
                }
            }
        }
    }

    private function dashboard(): array
    {
        return $this->getJson('/api/horses/'.$this->horse->id.'/dashboard?timezone=Europe/Amsterdam')->assertOk()->json('protocol');
    }

    /** @return array<int, string> */
    private function todayNames(array $protocol): array
    {
        return collect($protocol['today']['items'])->where('recordedOnly', '!=', true)->pluck('name')->unique()->sort()->values()->all();
    }

    private function phase(array $protocol, string $title): array
    {
        return collect($protocol['phases'])->firstWhere('title', $title);
    }

    public function test_a_following_phase_waits_for_catching_up_while_the_phases_alongside_run_on(): void
    {
        // Silsterk is given on 2 days of its week (credit 2 of 7); the rest as scheduled.
        $this->giveAsScheduled('2026-09-07', '2026-09-27', ['Silsterk']);
        foreach (['2026-09-21', '2026-09-22'] as $date) {
            ProtocolSupplementIntake::create(['protocol_phase_supplement_id' => $this->herbs['Silsterk']->id, 'horse_id' => $this->horse->id,
                'date' => $date, 'administration' => 1, 'dosage' => '20 g', 'done' => true]);
        }
        $this->giveAsScheduled('2026-09-28', '2026-10-04');

        $this->on('2026-10-05');
        $week5 = $this->dashboard();
        // Maagondersteuning and Mineralen are in week 5: no "verlengd", and Mineralen starts as planned.
        $this->assertSame('Week 5 van 12', $week5['weekLabel']);
        $this->assertSame(['Heemstwortel', 'Lijnzaad', 'Silsterk', 'Zink'], $this->todayNames($week5));
        $this->assertSame(['extended' => false, 'nextWeek' => null, 'items' => [['id' => $this->herbs['Silsterk']->id, 'name' => 'Silsterk', 'remaining' => 5]]], $week5['extension']);
        $this->assertSame('active', $this->phase($week5, 'Darm reset')['state']);
        $this->assertSame('active', $this->phase($week5, 'Mineralen aanvullen')['state']);
        $this->assertStringStartsWith('2026-10-05', $this->phase($week5, 'Mineralen aanvullen')['startsAt']);
        // Darmrevalidatie waits for Darm reset: 5 more days if everything is given.
        $this->assertSame('preview', $this->phase($week5, 'Darmrevalidatie')['state']);
        $this->assertStringStartsWith('2026-10-10', $this->phase($week5, 'Darmrevalidatie')['startsAt']);
        $this->assertStringStartsWith('2026-11-21', $this->phase($week5, 'Darmrevalidatie')['endsAt']);

        $this->giveAsScheduled('2026-10-05', '2026-10-09');
        $this->on('2026-10-10');
        $caughtUp = $this->dashboard();
        $this->assertSame('done', $this->phase($caughtUp, 'Darm reset')['state']);
        $this->assertSame('active', $this->phase($caughtUp, 'Darmrevalidatie')['state']);
        $this->assertSame(1, $this->phase($caughtUp, 'Darmrevalidatie')['currentPhaseWeek']);
        $this->assertSame(['Heemstwortel', 'Lijnzaad', 'Salie', 'Zink'], $this->todayNames($caughtUp));
        $this->assertNull($caughtUp['extension']);
        $this->assertSame('Week 5 van 12', $caughtUp['weekLabel']);
    }

    public function test_a_product_catching_up_alongside_a_running_phase_does_not_extend_the_week(): void
    {
        // The rule applies from week 6. Heemstwortel is not given in its last week (week 6).
        $this->protocol->update(['minimum_gifts_from' => '2026-10-12']);
        $this->giveAsScheduled('2026-10-12', '2026-11-01', ['Heemstwortel']);

        $this->on('2026-11-02');
        $week9 = $this->dashboard();
        $this->assertSame('Week 9 van 12', $week9['weekLabel']);
        $this->assertSame(['Heemstwortel', 'Zink'], $this->todayNames($week9));
        $this->assertSame(['extended' => false, 'nextWeek' => null, 'items' => [['id' => $this->herbs['Heemstwortel']->id, 'name' => 'Heemstwortel', 'remaining' => 7]]], $week9['extension']);
        $this->assertSame('active', $this->phase($week9, 'Maagondersteuning')['state']);
        $this->assertSame(8, $this->phase($week9, 'Maagondersteuning')['currentPhaseWeek']);
    }

    public function test_the_last_phase_catching_up_extends_its_last_week(): void
    {
        // The rule applies from week 12. Zink is not given in it.
        $this->protocol->update(['minimum_gifts_from' => '2026-11-23']);
        $this->on('2026-11-30');
        $extended = $this->dashboard();
        $this->assertSame('Week 12 van 12 · verlengd', $extended['weekLabel']);
        $this->assertFalse($extended['finished']);
        $this->assertSame(['Zink'], $this->todayNames($extended));
        $this->assertSame(['extended' => true, 'nextWeek' => null, 'items' => [['id' => $this->herbs['Zink']->id, 'name' => 'Zink', 'remaining' => 7]]], $extended['extension']);
    }
}
