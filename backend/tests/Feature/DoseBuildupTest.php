<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\AdminUser;
use App\Models\DoseBuildup;
use App\Models\Horse;
use App\Models\Protocol;
use App\Models\ProtocolPhaseSupplement;
use App\Models\ProtocolTemplate;
use App\Models\User;
use App\Support\HorseDashboard;
use App\Support\ProtocolDayHistory;
use App\Support\ProtocolDoseBuildup;
use Carbon\CarbonImmutable;
use Database\Seeders\DoseBuildupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DoseBuildupTest extends TestCase
{
    use RefreshDatabase;

    private Protocol $protocol;

    private ProtocolPhaseSupplement $item;

    private Horse $horse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'Europe/Amsterdam'));
        $owner = User::factory()->create();
        $this->horse = Horse::create(['owner_id' => $owner->id, 'name' => 'Buildup horse', 'weight_kg' => 600]);
        $template = ProtocolTemplate::create(['name' => 'Buildup']);
        $definition = $template->phases()->create(['name' => 'Phase', 'order' => 0]);
        $this->protocol = $this->horse->protocols()->create(['protocol_template_id' => $template->id, 'title' => 'Plan',
            'status' => 'active', 'published_at' => now()->subDays(4), 'started_at' => '2026-11-01', 'total_weeks' => 3]);
        $phase = $this->protocol->phases()->create(['protocol_template_phase_id' => $definition->id, 'title' => 'Phase', 'order' => 0, 'state' => 'active']);
        $this->item = $phase->supplements()->create(['name' => 'Herb', 'dosage' => '20 g', 'full_dose_amount' => 20, 'full_dose_unit' => 'g',
            'administrations_per_day' => 2, 'dose_buildup' => DoseBuildup::findOrFail(DoseBuildup::SEVEN_DAYS_ID)->snapshot()]);
        foreach (range(1, 3) as $number) {
            $week = $phase->weeks()->create(['number' => $number, 'protocol_week_number' => $number]);
            $this->item->weeks()->create(['protocol_phase_week_id' => $week->id]);
        }
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($owner->id)
        {
            public function __construct(private string $ownerId) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->ownerId);
                $request->attributes->set('powersync_claims', $request->header('X-Legacy') ? [] : ['protocol_doses' => 1]);

                return $next($request);
            }
        });
    }

    private function dose(string $date = '2026-11-05'): ?string
    {
        return app(ProtocolDoseBuildup::class)->forDay($this->item->fresh(), $date);
    }

    private function record(string $date, int $administration = 1, string $dosage = '5 g', bool $done = true)
    {
        return $this->item->intakes()->create(['horse_id' => $this->horse->id, 'date' => $date,
            'administration' => $administration, 'dosage' => $dosage, 'done' => $done]);
    }

    private function operation(string $date = '2026-11-05', int $administration = 1, ?string $dosage = null): array
    {
        $data = ['protocol_phase_supplement_id' => $this->item->id, 'horse_id' => $this->horse->id,
            'date' => $date, 'administration' => $administration, 'done' => true];
        if ($dosage !== null) {
            $data['dosage'] = $dosage;
        }

        return ['op' => 'PUT', 'type' => 'protocol_supplement_intakes', 'id' => (string) Str::uuid(), 'data' => $data];
    }

    private function upload(array $operations, bool $legacy = false)
    {
        return $this->withHeader('X-Legacy', $legacy ? '1' : '')->postJson('/api/sync/upload', [
            'timezone' => 'Europe/Amsterdam', 'operations' => $operations,
        ]);
    }

    private function admin(): void
    {
        $this->actingAs(AdminUser::create(['name' => 'Tester', 'email' => 'buildup@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]), 'admin');
    }

    public function test_linear_buildup_reaches_full_dose_on_seventh_recorded_day_and_caps_there(): void
    {
        $service = app(ProtocolDoseBuildup::class);
        foreach ([0.25, 0.375, 0.5, 0.625, 0.75, 0.875, 1, 1] as $priorDays => $ratio) {
            $this->assertEqualsWithDelta($ratio, $service->ratio($this->item->dose_buildup, $priorDays), 0.0000001);
        }
        $this->assertSame('5 g', $service->dose($this->item, 0));
        $this->assertSame('7.5 g', $service->dose($this->item, 1));
        $this->assertSame('15 g', $service->dose($this->item, 3));
        $this->assertSame('20 g', $service->dose($this->item, 20));
    }

    public function test_only_distinct_prior_recorded_dates_count_even_after_unchecking(): void
    {
        $this->assertSame('5 g', $this->dose());
        $this->record('2026-11-01');
        $second = $this->record('2026-11-01', 2);
        $second->update(['done' => false]);
        $this->assertSame('7.5 g', $this->dose());
        $this->record('2026-11-03', 1, '7.5 g', false);
        $this->record('2026-11-06'); // A future row cannot accelerate an earlier date.
        $this->assertSame('10 g', $this->dose());
        $other = $this->item->replicate();
        $other->save();
        $other->intakes()->create(['horse_id' => $this->horse->id, 'date' => '2026-11-04', 'dosage' => '20 g', 'done' => true]);
        $this->assertSame('10 g', $this->dose());
        $this->assertSame('5 g', app(ProtocolDoseBuildup::class)->forDay($other->fresh(), '2026-11-03'));
    }

    public function test_current_day_is_fixed_by_first_record_despite_late_prior_checkoffs_and_plan_changes(): void
    {
        $record = $this->record('2026-11-05');
        $this->record('2026-11-02');
        $this->item->update(['dosage' => '100 g', 'full_dose_amount' => 100]);
        $this->assertSame('5 g', $this->dose());
        $record->update(['dosage' => '100 g', 'done' => false]);
        $this->assertSame('5 g', $record->fresh()->dosage);
        $this->assertSame('5 g', $this->dose());
    }

    public function test_rounding_retains_gram_steps_and_uses_half_up_for_discrete_units(): void
    {
        $service = app(ProtocolDoseBuildup::class);
        foreach (['druppels', 'capsules', 'pillen'] as $unit) {
            $this->item->full_dose_unit = $unit;
            $this->item->full_dose_amount = 10;
            $this->assertSame('3 '.$unit, $service->dose($this->item, 0));
            $this->item->full_dose_amount = 9;
            $this->assertSame('2 '.$unit, $service->dose($this->item, 0));
            $this->item->full_dose_amount = 1;
            $this->assertSame('0 '.$unit, $service->dose($this->item, 0));
        }
        $this->item->full_dose_unit = 'g';
        $this->item->full_dose_amount = 10;
        $this->assertSame('2.5 g', $service->dose($this->item, 0));
        $this->item->full_dose_amount = 39;
        $this->assertSame('10 g', $service->dose($this->item, 0));
        $this->item->full_dose_amount = 41;
        $this->assertSame('15 g', $service->dose($this->item, 0));
        $this->item->full_dose_unit = 'ml';
        $this->assertSame('10.25 ml', $service->dose($this->item, 0));
    }

    public function test_custom_schedule_and_no_buildup(): void
    {
        $service = app(ProtocolDoseBuildup::class);
        $settings = ['type' => 'custom', 'days' => 3, 'schedule' => [
            ['day' => 1, 'dose_ratio' => 0.1], ['day' => 2, 'dose_ratio' => 0.6], ['day' => 3, 'dose_ratio' => 1],
        ]];
        $this->item->dose_buildup = $settings;
        $this->assertSame('2.5 g', $service->dose($this->item, 0));
        $this->assertSame('15 g', $service->dose($this->item, 1));
        $this->assertSame('20 g', $service->dose($this->item, 99));
        $this->item->dose_buildup = DoseBuildup::findOrFail(DoseBuildup::NONE_ID)->snapshot();
        $this->item->dosage = 'Handmatige instructie';
        $this->assertSame('Handmatige instructie', $service->dose($this->item, 0));
        $this->item->dose_buildup = null;
        $this->assertSame('Handmatige instructie', $service->dose($this->item, 99));
    }

    public function test_dashboard_uses_effective_dose_but_phase_overview_explains_full_dose_and_buildup(): void
    {
        $this->record('2026-11-02');
        $dashboard = app(HorseDashboard::class)->protocol($this->protocol->fresh(), CarbonImmutable::now('Europe/Amsterdam'));
        $this->assertSame(['7.5 g', '7.5 g'], array_column($dashboard['today']['items'], 'dosage'));
        $this->assertSame('20 g', $dashboard['phases'][0]['supplements'][0]['dosage']);
        $this->assertStringContainsString('7 geregistreerde dagen', $dashboard['phases'][0]['supplements'][0]['buildupLabel']);
    }

    public function test_sync_freezes_same_day_dose_and_preserves_it_on_retries_deletes_and_other_devices(): void
    {
        $first = $this->operation();
        $this->upload([$first])->assertOk();
        $this->assertSame('5 g', $this->item->intakes()->firstOrFail()->dosage);
        $this->record('2026-11-02');
        $evening = $this->operation(administration: 2, dosage: '7.5 g');
        $this->upload([$evening])->assertOk();
        $this->assertSame(['5 g', '5 g'], $this->item->intakes()->where('date', '2026-11-05')->pluck('dosage')->all());
        $first['data']['dosage'] = '20 g';
        $first['data']['done'] = false;
        $this->upload([$first])->assertOk();
        $this->upload([['op' => 'DELETE', 'type' => 'protocol_supplement_intakes', 'id' => $evening['id']]])->assertOk();
        $this->assertSame(2, $this->item->intakes()->where('date', '2026-11-05')->count());
        $this->assertSame(0, $this->item->intakes()->where('date', '2026-11-05')->where('done', true)->count());
        $this->assertSame('10 g', $this->dose('2026-11-06'));
    }

    public function test_legacy_whole_day_checkbox_uses_effective_dosage_for_both_administrations(): void
    {
        $op = $this->operation(dosage: '5 g');
        unset($op['data']['administration']);
        $this->upload([$op], true)->assertOk();
        $this->assertSame(['5 g', '5 g'], $this->item->intakes()->pluck('dosage')->all());
        $this->record('2026-11-02');
        $op['data']['done'] = false;
        $op['data']['dosage'] = '20 g';
        $this->upload([$op], true)->assertOk();
        $this->assertSame(['5 g', '5 g'], $this->item->intakes()->where('date', '2026-11-05')->pluck('dosage')->all());
    }

    public function test_offline_upload_retains_dosage_actually_displayed_and_new_history_uses_it(): void
    {
        // A client displayed day one while a second device had not uploaded an earlier record.
        $this->record('2026-11-02');
        $this->upload([$this->operation(dosage: '5 g')])->assertOk();
        $this->assertSame('5 g', $this->dose());
        $history = app(ProtocolDayHistory::class);
        $history->preserve($this->protocol, CarbonImmutable::parse('2026-11-06 12:00', 'Europe/Amsterdam'));
        $day = $history->days($this->protocol, '2026-11')->get('2026-11-05');
        $this->assertSame(['5 g', '5 g'], array_column($day, 'dosage'));
    }

    private function snapshotItems(string $date = '2026-11-04'): array
    {
        return json_decode(DB::table('protocol_day_snapshots')->where('protocol_id', $this->protocol->id)
            ->where('date', $date)->value('items'), true);
    }

    public function test_seeded_custom_buildup_uses_half_dose_for_two_recorded_days_then_full_dose(): void
    {
        $buildup = DoseBuildup::findOrFail(DoseBuildup::TWO_DAYS_HALF_ID);
        $fromMigration = $buildup->snapshot();
        $this->assertSame('custom', $fromMigration['type']);
        $this->assertSame(3, $buildup->days);
        $this->assertSame(0.5, $buildup->start_dose_ratio);
        // Exercise the seeder's create path as well as the migration's definition.
        $buildup->delete();
        $this->seed(DoseBuildupSeeder::class);
        $this->seed(DoseBuildupSeeder::class);
        $this->assertSame($fromMigration, DoseBuildup::findOrFail(DoseBuildup::TWO_DAYS_HALF_ID)->snapshot());
        $this->assertSame(3, DoseBuildup::count());
        $this->item->update(['dose_buildup' => $fromMigration]);
        $this->assertSame('10 g', $this->dose());
        $this->record('2026-11-01', dosage: '10 g');
        $this->record('2026-11-01', 2, '10 g');
        $this->assertSame('10 g', $this->dose());
        $this->record('2026-11-03', dosage: '10 g');
        $this->assertSame('20 g', $this->dose());
        $this->record('2026-11-04', dosage: '20 g');
        $this->assertSame('20 g', $this->dose());
    }

    public function test_delayed_past_intakes_display_recorded_dose_without_overwriting_frozen_plan(): void
    {
        $this->record('2026-11-02');
        $first = $this->operation('2026-11-04', dosage: '5 g');
        // Validation freezes this day at 7.5 g before accepting the offline 5 g record.
        $this->upload([$first])->assertOk();
        $history = app(ProtocolDayHistory::class);
        $now = CarbonImmutable::now('Europe/Amsterdam');
        $this->assertSame(['7.5 g', '7.5 g'], array_column($this->snapshotItems(), 'dosage'));
        $this->assertSame('5 g', $this->snapshotItems()[0]['recorded_dosage']);
        $this->assertArrayNotHasKey('recorded_dosage', $this->snapshotItems()[1]);
        $this->assertSame(['5 g', '7.5 g'], array_column($history->day($this->protocol, '2026-11-04', $now)['items'], 'dosage'));

        // A second device and a retry must use the already accepted amount.
        $second = $this->operation('2026-11-04', 2, '20 g');
        $this->upload([$second])->assertOk();
        $first['data']['dosage'] = '100 g';
        $first['data']['done'] = false;
        $this->upload([$first])->assertOk();
        $this->upload([['op' => 'DELETE', 'type' => 'protocol_supplement_intakes', 'id' => $second['id']]])->assertOk();
        $this->item->update(['full_dose_amount' => 100]);
        $day = $history->setDone($this->protocol, '2026-11-04', $this->item->id, true, $now, administration: 2);
        $this->assertSame(['5 g', '5 g'], array_column($day['items'], 'dosage'));
        $this->assertSame(['7.5 g', '7.5 g'], array_column($this->snapshotItems(), 'dosage'));
        $this->assertSame(['5 g', '5 g'], array_column($this->snapshotItems(), 'recorded_dosage'));
        $this->assertSame(['5 g', '5 g'], array_column($history->days($this->protocol, '2026-11')->get('2026-11-04'), 'dosage'));
        $this->assertSame(['5 g', '5 g'], $this->item->intakes()->where('date', '2026-11-04')->pluck('dosage')->all());
        $this->getJson('/api/horses/'.$this->horse->id.'/protocol-day?'.http_build_query([
            'protocol_id' => $this->protocol->id, 'date' => '2026-11-04', 'timezone' => 'Europe/Amsterdam',
        ]))->assertOk()->assertJsonPath('items.0.dosage', '5 g')->assertJsonPath('items.1.dosage', '5 g');
    }

    public function test_evening_first_delayed_upload_reconciles_the_morning_compatibility_dose_only(): void
    {
        $this->record('2026-11-02');
        $history = app(ProtocolDayHistory::class);
        $history->preserve($this->protocol, CarbonImmutable::now('Europe/Amsterdam'));
        $otherDay = $this->snapshotItems('2026-11-03');
        $evening = $this->operation('2026-11-04', 2, '5 g');
        $this->upload([$evening])->assertOk();

        $intakes = $this->item->intakes()->where('date', '2026-11-04')->orderBy('administration')->get();
        $this->assertSame(['5 g', '5 g'], $intakes->pluck('dosage')->all());
        $this->assertSame([false, true], $intakes->pluck('done')->all());
        $this->assertSame(['7.5 g', '7.5 g'], array_column($this->snapshotItems(), 'dosage'));
        $this->assertSame(['5 g', '5 g'], array_column($this->snapshotItems(), 'recorded_dosage'));
        $this->assertSame([false, true], array_column($this->snapshotItems(), 'done'));

        $url = '/api/horses/'.$this->horse->id.'/protocol-day';
        $query = ['protocol_id' => $this->protocol->id, 'date' => '2026-11-04', 'timezone' => 'Europe/Amsterdam'];
        $this->getJson($url.'?'.http_build_query($query))->assertOk()
            ->assertJsonPath('items.0.dosage', '5 g')->assertJsonPath('items.0.done', false)
            ->assertJsonPath('items.1.dosage', '5 g')->assertJsonPath('items.1.done', true);
        $this->withHeader('X-Legacy', '1')->getJson($url.'?'.http_build_query($query))->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.dosage', '5 g')->assertJsonPath('items.0.done', false);

        $evening['data']['dosage'] = '20 g';
        $evening['data']['done'] = false;
        $this->upload([$evening])->assertOk();
        $this->postJson($url, $query + ['item_id' => $this->item->id, 'administration' => 1, 'done' => true])
            ->assertOk()->assertJsonPath('items.0.done', true)->assertJsonPath('items.1.done', false)
            ->assertJsonPath('items.0.dosage', '5 g')->assertJsonPath('items.1.dosage', '5 g');
        $this->assertSame(['7.5 g', '7.5 g'], array_column($this->snapshotItems(), 'dosage'));
        $this->assertSame(['5 g', '5 g'], array_column($this->snapshotItems(), 'recorded_dosage'));
        $this->assertSame($otherDay, $this->snapshotItems('2026-11-03'));
    }

    public function test_delayed_legacy_whole_day_intake_updates_both_recorded_doses_in_existing_history(): void
    {
        $this->record('2026-11-02');
        $history = app(ProtocolDayHistory::class);
        $history->preserve($this->protocol, CarbonImmutable::now('Europe/Amsterdam'));
        $otherDay = $this->snapshotItems('2026-11-03');
        $op = $this->operation('2026-11-04', dosage: '5 g');
        unset($op['data']['administration']);
        $this->upload([$op], true)->assertOk();
        $op['data']['done'] = false;
        $op['data']['dosage'] = '20 g';
        $this->upload([$op], true)->assertOk();
        $this->assertSame(['7.5 g', '7.5 g'], array_column($this->snapshotItems(), 'dosage'));
        $this->assertSame(['5 g', '5 g'], array_column($this->snapshotItems(), 'recorded_dosage'));
        $this->assertSame(['5 g', '5 g'], $this->item->intakes()->where('date', '2026-11-04')->pluck('dosage')->all());
        $this->assertSame([false, false], array_column($this->snapshotItems(), 'done'));
        $this->assertSame($otherDay, $this->snapshotItems('2026-11-03'));
        $this->getJson('/api/horses/'.$this->horse->id.'/protocol-day?'.http_build_query([
            'protocol_id' => $this->protocol->id, 'date' => '2026-11-04', 'timezone' => 'Europe/Amsterdam',
        ]))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.dosage', '5 g');
    }

    public function test_rejected_upload_rolls_back_recorded_dosage_and_intake_together(): void
    {
        $this->record('2026-11-02');
        app(ProtocolDayHistory::class)->preserve($this->protocol, CarbonImmutable::now('Europe/Amsterdam'));
        $before = $this->snapshotItems();
        $this->upload([$this->operation('2026-11-04', dosage: '5 g'), $this->operation('2026-11-06')])->assertUnprocessable();
        $this->assertSame($before, $this->snapshotItems());
        $this->assertSame(0, $this->item->intakes()->where('date', '2026-11-04')->count());
    }

    public function test_existing_history_is_not_recalculated_after_late_records_or_buildup_changes(): void
    {
        $history = app(ProtocolDayHistory::class);
        $history->preserve($this->protocol, CarbonImmutable::now('Europe/Amsterdam'));
        $before = DB::table('protocol_day_snapshots')->where('protocol_id', $this->protocol->id)->pluck('items', 'date')->all();
        $this->record('2026-11-01');
        $this->item->update(['full_dose_amount' => 100]);
        $history->preserve($this->protocol, CarbonImmutable::now('Europe/Amsterdam'));
        $this->assertSame($before, DB::table('protocol_day_snapshots')->where('protocol_id', $this->protocol->id)->pluck('items', 'date')->all());
        $history->setDone($this->protocol, '2026-11-03', $this->item->id, true, CarbonImmutable::now('Europe/Amsterdam'));
        $this->assertSame('5 g', $this->item->intakes()->where('date', '2026-11-03')->value('dosage'));
    }

    public function test_admin_can_save_custom_schedule_and_invalid_schedules_are_rejected(): void
    {
        $this->admin();
        $data = ['name' => 'Custom', 'type' => 'custom', 'schedule' => [
            ['day' => 1, 'dose_ratio' => 0.2], ['day' => 2, 'dose_ratio' => 1],
        ]];
        $this->postJson('/admin/protocol-settings/dose-buildups', $data)->assertRedirect();
        $saved = DoseBuildup::where('name', 'Custom')->firstOrFail();
        $this->assertSame(2, $saved->days);
        $this->assertSame(0.2, $saved->start_dose_ratio);
        $this->assertSame($data['schedule'], $saved->schedule);
        $this->postJson('/admin/protocol-settings/dose-buildups', [
            'name' => 'Back to linear', 'type' => 'linear', 'days' => 7, 'start_dose_ratio' => 0.25, 'schedule' => [],
        ])->assertRedirect();
        $data['schedule'][1]['day'] = 3;
        $this->postJson('/admin/protocol-settings/dose-buildups', $data)->assertUnprocessable()->assertJsonValidationErrors('schedule');
        $data['schedule'][1] = ['day' => 2, 'dose_ratio' => 0.9];
        $this->postJson('/admin/protocol-settings/dose-buildups', $data)->assertUnprocessable()->assertJsonValidationErrors('schedule');
        $this->putJson('/admin/protocol-settings/dose-buildups/'.DoseBuildup::NONE_ID, [
            'name' => 'Changed', 'type' => 'linear', 'start_dose_ratio' => 0.1, 'days' => 10,
        ])->assertUnprocessable();
    }

    public function test_buildup_names_can_change_without_changing_protocol_snapshots_and_seed_is_idempotent(): void
    {
        $original = $this->item->dose_buildup;
        $this->admin();
        foreach ([DoseBuildup::SEVEN_DAYS_ID, DoseBuildup::NONE_ID, DoseBuildup::TWO_DAYS_HALF_ID] as $id) {
            $this->putJson('/admin/protocol-settings/dose-buildups/'.$id, ['name' => 'Renamed '.$id])->assertRedirect();
        }
        $this->seed(DoseBuildupSeeder::class);
        $this->assertSame($original, $this->item->fresh()->dose_buildup);
        $this->assertSame('5 g', $this->dose());
        $this->assertSame('Renamed '.DoseBuildup::SEVEN_DAYS_ID, DoseBuildup::findOrFail(DoseBuildup::SEVEN_DAYS_ID)->name);
        $this->assertSame(0.25, DoseBuildup::findOrFail(DoseBuildup::SEVEN_DAYS_ID)->start_dose_ratio);
        $this->assertSame(3, DoseBuildup::count());
    }

    public function test_existing_dosing_settings_cannot_be_changed_through_http_or_the_model(): void
    {
        $this->admin();
        $original = DoseBuildup::findOrFail(DoseBuildup::SEVEN_DAYS_ID)->snapshot();
        foreach (['type' => 'custom', 'start_dose_ratio' => 0.5, 'days' => 3,
            'schedule' => [['day' => 1, 'dose_ratio' => 1]]] as $field => $value) {
            $this->putJson('/admin/protocol-settings/dose-buildups/'.DoseBuildup::SEVEN_DAYS_ID, [
                'name' => 'Should not save', $field => $value,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
            try {
                DoseBuildup::findOrFail(DoseBuildup::SEVEN_DAYS_ID)->update([$field => $value]);
                $this->fail('Expected immutable dosing settings to be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
            $this->assertSame($original, DoseBuildup::findOrFail(DoseBuildup::SEVEN_DAYS_ID)->snapshot());
        }
    }

    public function test_a_revised_copy_is_created_with_a_new_id_and_preserves_the_original(): void
    {
        $this->admin();
        $original = DoseBuildup::findOrFail(DoseBuildup::SEVEN_DAYS_ID)->snapshot();
        $copy = Arr::except($original, ['id']);
        $copy['name'] = 'Faster copy';
        $copy['days'] = 3;
        $copy['start_dose_ratio'] = 0.5;
        $this->postJson('/admin/protocol-settings/dose-buildups', $copy)->assertRedirect();
        $created = DoseBuildup::where('name', 'Faster copy')->sole();
        $this->assertNotSame($original['id'], $created->id);
        $this->assertSame(3, $created->days);
        $this->assertSame(0.5, $created->start_dose_ratio);
        $this->assertSame($original, DoseBuildup::findOrFail($original['id'])->snapshot());
        $this->assertSame($original, $this->item->fresh()->dose_buildup);
    }

    public function test_migration_defaults_existing_plans_to_no_buildup_without_modifying_recorded_history(): void
    {
        $record = $this->record('2026-11-02', dosage: 'Legacy text');
        $history = app(ProtocolDayHistory::class);
        $history->preserve($this->protocol, CarbonImmutable::now('Europe/Amsterdam'));
        $before = DB::table('protocol_day_snapshots')->where('protocol_id', $this->protocol->id)->get()->toArray();
        $migration = require database_path('migrations/2026_11_05_000001_add_dose_buildups.php');
        $migration->down();
        $migration->up();
        $this->assertNull($this->item->fresh()->dose_buildup);
        $this->assertNull($this->item->fresh()->full_dose_amount);
        $this->assertSame('Legacy text', $record->fresh()->dosage);
        $this->assertEquals($before, DB::table('protocol_day_snapshots')->where('protocol_id', $this->protocol->id)->get()->toArray());
        $catalog = $this->item->phase->phase()->firstOrFail()->supplements()->create(['name' => 'Default', 'supplement_type' => 'supplement']);
        $this->assertSame(DoseBuildup::NONE_ID, $catalog->fresh()->dose_buildup_id);
    }
}
