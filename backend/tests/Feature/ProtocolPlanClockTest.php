<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\Horse;
use App\Models\Protocol;
use App\Models\ProtocolPhaseSupplement;
use App\Models\ProtocolSupplementIntake;
use App\Models\ProtocolTemplate;
use App\Models\User;
use App\Support\ProtocolEvaluation;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The plan clock (ProtocolClock): weeks run on plan days, each product earns its weeks by its minimum per week,
 * and products still short catch up at the end of their phase while its last week is extended.
 *
 * Phase 1 is week 1-2 (Brandnetel in week 1-2, Kurkuma in week 1), phase 2 is week 3-4 (Mariadistel).
 * The plan starts on Monday 3 August: week 1 is 3-9 August, week 2 is 10-16 August, week 3 starts 17 August.
 */
class ProtocolPlanClockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Horse $horse;

    private Protocol $protocol;

    private ProtocolPhaseSupplement $brandnetel;

    private ProtocolPhaseSupplement $kurkuma;

    private ProtocolPhaseSupplement $mariadistel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->on('2026-08-03');
        $this->owner = User::factory()->create();
        $this->horse = Horse::create(['owner_id' => $this->owner->id, 'name' => 'Bella', 'weight_kg' => 600, 'status' => 'active']);
        $template = ProtocolTemplate::create(['name' => 'Test protocol']);
        $this->protocol = $this->horse->protocols()->create([
            'protocol_template_id' => $template->id, 'title' => 'Plan', 'status' => 'active',
            'published_at' => now()->subWeek(), 'started_at' => '2026-08-03', 'total_weeks' => 4, 'current_week' => 1,
        ]);
        $herbs = [];
        foreach ([[1, 2, ['Brandnetel' => [1, 2], 'Kurkuma' => [1]]], [3, 4, ['Mariadistel' => [3, 4]]]] as $index => [$first, $last, $products]) {
            $definition = $template->phases()->create(['name' => 'Fase '.($index + 1), 'order' => $index]);
            $phase = $this->protocol->phases()->create(['protocol_template_phase_id' => $definition->id, 'title' => 'Fase '.($index + 1),
                'state' => 'upcoming', 'order' => $index, 'week_start' => $first, 'week_end' => $last]);
            $weeks = [];
            foreach (range($first, $last) as $local => $global) {
                $weeks[$global] = $phase->weeks()->create(['number' => $local + 1, 'protocol_week_number' => $global]);
            }
            foreach ($products as $name => $numbers) {
                $herb = $phase->supplements()->create(['name' => $name, 'dosage' => '20 g',
                    'min_aantal_per_week' => 4, 'max_aantal_in_fase' => 7 * count($numbers)]);
                foreach ($numbers as $number) {
                    $herb->weeks()->create(['protocol_phase_week_id' => $weeks[$number]->id]);
                }
                $herbs[$name] = $herb;
            }
        }
        ['Brandnetel' => $this->brandnetel, 'Kurkuma' => $this->kurkuma, 'Mariadistel' => $this->mariadistel] = $herbs;
        $ownerId = $this->owner->id;
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

    private function give(ProtocolPhaseSupplement $herb, string $from, ?string $until = null): void
    {
        foreach (CarbonPeriod::create($from, $until ?? $from) as $day) {
            ProtocolSupplementIntake::create(['protocol_phase_supplement_id' => $herb->id, 'horse_id' => $this->horse->id,
                'date' => $day->toDateString(), 'administration' => 1, 'dosage' => '20 g', 'done' => true]);
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

    private function phase(array $protocol, int $index): array
    {
        return $protocol['phases'][$index];
    }

    public function test_a_week_short_of_the_minimum_moves_nothing_and_the_product_catches_up_at_the_end_of_its_phase(): void
    {
        // Brandnetel: 2 days in week 1 (credit 2), all of week 2 (credit 9 of 14). Kurkuma: all of week 1.
        $this->give($this->brandnetel, '2026-08-03', '2026-08-04');
        $this->give($this->kurkuma, '2026-08-03', '2026-08-09');
        $this->give($this->brandnetel, '2026-08-10', '2026-08-16');

        $this->on('2026-08-10');
        $week2 = $this->dashboard();
        $this->assertSame(2, $week2['currentWeek']);
        $this->assertSame('Week 2 van 4', $week2['weekLabel']);
        $this->assertSame(['Brandnetel'], $this->todayNames($week2));
        $this->assertNull($week2['extension']);

        $this->on('2026-08-17');
        $extended = $this->dashboard();
        $this->assertSame(2, $extended['currentWeek']);
        $this->assertSame(14, $extended['currentDay']);
        $this->assertSame('Week 2 van 4 · verlengd', $extended['weekLabel']);
        $this->assertStringStartsWith('Week 2 van 4 · verlengd · ', $extended['todayLabel']);
        $this->assertSame(['Brandnetel'], $this->todayNames($extended));
        $this->assertSame(['extended' => true, 'nextWeek' => 3, 'items' => [['id' => $this->brandnetel->id, 'name' => 'Brandnetel', 'remaining' => 5]]], $extended['extension']);
        $this->assertSame('active', $this->phase($extended, 0)['state']);
        $this->assertSame('preview', $this->phase($extended, 1)['state']);
        // Coming days are expected to be given: phase 2 starts after the 5 days still needed.
        $this->assertStringStartsWith('2026-08-22', $this->phase($extended, 1)['startsAt']);

        // Days in the extension count one by one; today's tick does not change today.
        $this->give($this->brandnetel, '2026-08-17', '2026-08-21');
        $this->on('2026-08-21');
        $lastDay = $this->dashboard();
        $this->assertSame(1, $lastDay['extension']['items'][0]['remaining']);
        $this->assertSame(['Brandnetel'], $this->todayNames($lastDay));

        $this->on('2026-08-22');
        $phase2 = $this->dashboard();
        $this->assertSame(3, $phase2['currentWeek']);
        $this->assertSame('Week 3 van 4', $phase2['weekLabel']);
        $this->assertSame(['Mariadistel'], $this->todayNames($phase2));
        $this->assertNull($phase2['extension']);
        $this->assertSame('done', $this->phase($phase2, 0)['state']);
        $this->assertSame('active', $this->phase($phase2, 1)['state']);
        $this->assertSame(1, $this->phase($phase2, 1)['currentPhaseWeek']);
    }

    public function test_a_product_short_in_an_early_week_waits_for_the_end_of_its_phase(): void
    {
        // Kurkuma (week 1 only) is given on 2 days; Brandnetel on every day of its weeks.
        $this->give($this->kurkuma, '2026-08-03', '2026-08-04');
        $this->give($this->brandnetel, '2026-08-03', '2026-08-16');

        $this->on('2026-08-12');
        $this->assertSame(['Brandnetel'], $this->todayNames($this->dashboard()));

        $this->on('2026-08-17');
        $extended = $this->dashboard();
        $this->assertSame(['Kurkuma'], $this->todayNames($extended));
        $this->assertSame([['id' => $this->kurkuma->id, 'name' => 'Kurkuma', 'remaining' => 5]], $extended['extension']['items']);
    }

    public function test_four_days_a_week_count_as_whole_weeks(): void
    {
        foreach (['2026-08-03', '2026-08-10'] as $monday) {
            $this->give($this->brandnetel, $monday, CarbonImmutable::parse($monday)->addDays(3)->toDateString());
        }
        $this->give($this->kurkuma, '2026-08-05', '2026-08-08');

        $this->on('2026-08-17');
        $week3 = $this->dashboard();
        $this->assertSame('Week 3 van 4', $week3['weekLabel']);
        $this->assertSame(['Mariadistel'], $this->todayNames($week3));
        $this->assertNull($week3['extension']);
    }

    public function test_a_product_stops_at_its_maximum_in_the_phase(): void
    {
        $this->brandnetel->update(['max_aantal_in_fase' => 7]);
        $this->give($this->brandnetel, '2026-08-03', '2026-08-09');
        $this->give($this->kurkuma, '2026-08-03', '2026-08-09');

        $this->on('2026-08-10');
        $week2 = $this->dashboard();
        $this->assertSame([], $this->todayNames($week2));
        $this->on('2026-08-17');
        $this->assertSame('Week 3 van 4', $this->dashboard()['weekLabel']);
    }

    public function test_only_the_products_still_short_can_be_ticked_off_during_an_extension(): void
    {
        $this->give($this->kurkuma, '2026-08-03', '2026-08-09');
        $this->give($this->brandnetel, '2026-08-03', '2026-08-04');
        $this->on('2026-08-17');
        $operation = fn (ProtocolPhaseSupplement $herb) => ['op' => 'PUT', 'type' => 'protocol_supplement_intakes', 'id' => (string) Str::uuid(),
            'data' => ['horse_id' => $this->horse->id, 'protocol_phase_supplement_id' => $herb->id, 'date' => '2026-08-17', 'done' => true, 'administration' => 1]];

        $this->postJson('/api/sync/upload', ['operations' => [$operation($this->brandnetel)]])->assertOk()->assertJson(['applied' => 1]);
        $this->postJson('/api/sync/upload', ['operations' => [$operation($this->mariadistel)]])->assertUnprocessable();
        $this->postJson('/api/sync/upload', ['operations' => [$operation($this->kurkuma)]])->assertUnprocessable();
    }

    public function test_the_weekly_update_comes_every_seven_plan_days_also_during_an_extension(): void
    {
        $this->give($this->kurkuma, '2026-08-03', '2026-08-09');
        $this->give($this->brandnetel, '2026-08-03', '2026-08-04');
        $url = '/api/horses/'.$this->horse->id;
        foreach (['2026-08-09' => 1, '2026-08-16' => 2] as $date => $number) {
            $this->on($date);
            $this->assertSame('weekly-'.$number, collect($this->dashboard()['notifications'])->firstWhere('type', 'weekly_update')['id']);
            $this->postJson($url.'/weekly-update', ['protocol_id' => $this->protocol->id, 'note' => 'Week '.$number, 'timezone' => 'Europe/Amsterdam'])->assertOk();
        }

        // Plan day 21: still week 2 (extended), and a third update is asked for.
        $this->on('2026-08-23');
        $extended = $this->dashboard();
        $this->assertSame('Week 2 van 4 · verlengd', $extended['weekLabel']);
        $due = collect($extended['notifications'])->firstWhere('type', 'weekly_update');
        $this->assertSame('weekly-3', $due['id']);
        $this->assertSame('Hoe gaat het met Bella in week 2?', $due['body']);
        $this->postJson($url.'/weekly-update', ['protocol_id' => $this->protocol->id, 'note' => 'Verlengd', 'timezone' => 'Europe/Amsterdam'])->assertOk();
        $this->assertSame(['Week 1', 'Week 2', 'Verlengd'], DB::table('protocol_weekly_updates')->where('protocol_id', $this->protocol->id)
            ->orderBy('week_number')->pluck('note')->all());
        $this->assertNull(collect($this->dashboard()['notifications'])->firstWhere('type', 'weekly_update'));
    }

    public function test_paused_days_are_no_plan_days(): void
    {
        $this->give($this->brandnetel, '2026-08-03', '2026-08-05');
        $this->give($this->kurkuma, '2026-08-03', '2026-08-05');
        $this->on('2026-08-05');
        $this->protocol->update(['status' => 'paused']);
        $this->on('2026-08-12');
        $this->protocol->fresh()->update(['status' => 'active']);
        $this->assertSame([['after' => '2026-08-05', 'before' => '2026-08-12']], $this->protocol->fresh()->paused_periods);

        // 3, 4 and 5 August and today: plan day 4 of week 1. Week 3 starts 6 days later than planned.
        $today = $this->dashboard();
        $this->assertSame(1, $today['currentWeek']);
        $this->assertSame(4, $today['currentDay']);
        $this->assertSame(['Brandnetel', 'Kurkuma'], $this->todayNames($today));
        $this->assertStringStartsWith('2026-08-23', $this->phase($today, 1)['startsAt']);

        // A paused day is not frozen as missed.
        $this->assertFalse(DB::table('protocol_day_snapshots')->where('protocol_id', $this->protocol->id)->where('date', '2026-08-08')->exists());
        $this->assertTrue(DB::table('protocol_day_snapshots')->where('protocol_id', $this->protocol->id)->where('date', '2026-08-05')->exists());
    }

    public function test_a_paused_plan_keeps_its_week_and_an_overnight_pause_skips_nothing(): void
    {
        $this->on('2026-08-06');
        $this->protocol->update(['status' => 'paused']);
        $this->on('2026-08-20');
        $paused = $this->dashboard();
        $this->assertSame(1, $paused['currentWeek']);
        $this->assertNull($paused['extension']);

        $this->protocol->fresh()->update(['status' => 'active']);
        $this->on('2026-08-21');
        $this->protocol->fresh()->update(['status' => 'paused']);
        $this->on('2026-08-22');
        $this->protocol->fresh()->update(['status' => 'active']);
        // Only 6 August to 20 August is skipped; the night of 21 to 22 August is not.
        $this->assertSame([['after' => '2026-08-06', 'before' => '2026-08-20']], $this->protocol->fresh()->paused_periods);
    }

    public function test_days_before_the_rule_count_as_given(): void
    {
        $this->protocol->update(['minimum_gifts_from' => '2026-12-01']);
        $this->on('2026-08-17');
        $week3 = $this->dashboard();
        $this->assertSame('Week 3 van 4', $week3['weekLabel']);
        $this->assertSame(['Mariadistel'], $this->todayNames($week3));
    }

    public function test_the_last_phase_extends_the_plan_and_moves_its_end(): void
    {
        $this->protocol->update(['minimum_gifts_from' => '2026-08-17']);
        $this->give($this->mariadistel, '2026-08-17', '2026-08-23');
        $this->on('2026-08-31');
        $extended = $this->dashboard();
        $this->assertSame('Week 4 van 4 · verlengd', $extended['weekLabel']);
        $this->assertFalse($extended['finished']);
        $this->assertSame(['extended' => true, 'nextWeek' => null, 'items' => [['id' => $this->mariadistel->id, 'name' => 'Mariadistel', 'remaining' => 7]]], $extended['extension']);
        // The evaluation counts from the moved end: everything given from today, the last day is 6 September.
        $this->assertSame('2026-09-06', app(ProtocolEvaluation::class)->timing($this->protocol->fresh(), CarbonImmutable::now('Europe/Amsterdam'))['endsOn']);
    }
}
