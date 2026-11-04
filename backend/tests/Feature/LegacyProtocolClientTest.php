<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\Protocol;
use App\Models\ProtocolPhaseSupplement;
use App\Models\ProtocolTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyProtocolClientTest extends TestCase
{
    use RefreshDatabase;

    private Protocol $protocol;

    private ProtocolPhaseSupplement $item;

    private string $horseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(12, 0));
        $owner = User::factory()->create();
        $horse = Horse::create(['owner_id' => $owner->id, 'name' => 'Legacy horse', 'status' => 'active']);
        $this->horseId = $horse->id;
        $template = ProtocolTemplate::create(['name' => 'Compatibility']);
        $definition = $template->phases()->create(['name' => 'Phase', 'order' => 1]);
        $this->protocol = $horse->protocols()->create(['protocol_template_id' => $template->id, 'title' => 'Plan',
            'status' => 'active', 'published_at' => now()->subDays(3), 'started_at' => '2026-11-01', 'total_weeks' => 1]);
        $phase = $this->protocol->phases()->create(['protocol_template_phase_id' => $definition->id, 'title' => 'Phase',
            'order' => 1, 'state' => 'active', 'week_start' => 1, 'week_end' => 1]);
        $week = $phase->weeks()->create(['number' => 1, 'protocol_week_number' => 1]);
        $this->item = $phase->supplements()->create(['name' => 'Herb', 'dosage' => '10 g', 'administrations_per_day' => 2]);
        $this->item->weeks()->create(['protocol_phase_week_id' => $week->id]);
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($owner->id)
        {
            public function __construct(private string $ownerId) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->ownerId);
                $request->attributes->set('powersync_claims', $request->header('X-Test-Modern') ? ['protocol_doses' => 1] : []);

                return $next($request);
            }
        });
    }

    private function operation(int $administration = 1): array
    {
        return ['op' => 'PUT', 'type' => 'protocol_supplement_intakes', 'id' => (string) Str::uuid(), 'data' => [
            'protocol_phase_supplement_id' => $this->item->id, 'horse_id' => $this->horseId, 'date' => '2026-11-04',
            'administration' => $administration, 'dosage' => '10 g', 'done' => true,
        ]];
    }

    private function upload(array $operations, bool $modern = false)
    {
        return $this->withHeader('X-Test-Modern', $modern ? '1' : '')->postJson('/api/sync/upload', [
            'timezone' => 'Europe/Amsterdam', 'operations' => $operations,
        ]);
    }

    public function test_dashboard_defaults_to_selection_orders_and_unique_daily_rows_but_modern_clients_get_doses(): void
    {
        $url = '/api/horses/'.$this->horseId.'/dashboard';
        $legacy = $this->getJson($url)->assertOk()->json('protocol');
        $this->assertCount(1, $legacy['today']['items']);
        $this->assertSame($this->item->id, $legacy['orderItems'][0]['id']);
        $this->assertSame('10 g', $legacy['orderItems'][0]['dosage']);
        $modern = $this->withHeader('X-Test-Modern', '1')->getJson($url)->assertOk()->json('protocol');
        $this->assertCount(2, $modern['today']['items']);
        $this->assertSame([1, 2], array_column($modern['today']['items'], 'administration'));
        $this->assertSame('140 g', $modern['orderItems'][0]['quantityLabel']);
    }

    public function test_legacy_daily_actions_and_modern_single_doses_stay_consistent_in_both_directions(): void
    {
        $old = $this->operation();
        unset($old['data']['administration']); // Exact released app upload shape.
        $this->upload([$old])->assertOk()->assertJsonPath('applied', 1);
        $this->assertSame([true, true], $this->item->intakes()->orderBy('administration')->pluck('done')->all());
        $evening = $this->item->intakes()->where('administration', 2)->firstOrFail();
        $this->upload([['op' => 'PATCH', 'type' => 'protocol_supplement_intakes', 'id' => $evening->id, 'data' => ['done' => false]]], true)->assertOk();
        $this->assertSame([true, false], $this->item->intakes()->orderBy('administration')->pluck('done')->all());
        $this->assertFalse((bool) DB::table('protocol_supplement_intakes')->where('id', $old['id'])->value('day_done'));
        $this->upload([['op' => 'PATCH', 'type' => 'protocol_supplement_intakes', 'id' => $old['id'], 'data' => ['done' => true]]])->assertOk();
        $this->assertSame([true, true], $this->item->intakes()->orderBy('administration')->pluck('done')->all());
        $this->upload([['op' => 'DELETE', 'type' => 'protocol_supplement_intakes', 'id' => $old['id']]])->assertOk();
        $this->assertSame([false, false], $this->item->intakes()->orderBy('administration')->pluck('done')->all());
    }

    public function test_upgraded_client_preserves_whole_day_semantics_of_queued_legacy_puts(): void
    {
        foreach (['2026-11-04', '2026-11-03'] as $date) {
            $old = $this->operation();
            unset($old['data']['administration']);
            $old['data']['date'] = $date;
            // A new token uploads an operation persisted by the previous app version.
            $this->upload([$old], true)->assertOk();
            $rows = fn () => $this->item->intakes()->whereDate('date', $date)->orderBy('administration')->pluck('done')->all();
            $this->assertSame([true, true], $rows());
            $this->upload([$old], true)->assertOk();
            $this->assertSame([true, true], $rows());
            $old['data']['done'] = false;
            $this->upload([$old], true)->assertOk();
            $this->assertSame([false, false], $rows());
            // New inserts name their dose explicitly and must still change only that dose.
            $modern = $old;
            $modern['data']['administration'] = 1;
            $modern['data']['done'] = true;
            $this->upload([$modern], true)->assertOk();
            $this->assertSame([true, false], $rows());
        }
    }

    public function test_evening_first_and_deleting_morning_leave_a_false_daily_projection(): void
    {
        $this->upload([$this->operation(2)], true)->assertOk();
        $morning = $this->item->intakes()->where('administration', 1)->firstOrFail();
        $this->assertFalse($morning->done);
        $this->assertFalse((bool) $morning->day_done);
        $this->upload([['op' => 'DELETE', 'type' => 'protocol_supplement_intakes', 'id' => $morning->id]], true)->assertOk();
        $this->assertFalse((bool) $this->item->intakes()->where('administration', 1)->firstOrFail()->day_done);
    }

    public function test_historical_daily_toggle_updates_all_saved_doses_and_new_clients_can_correct_one(): void
    {
        $url = '/api/horses/'.$this->horseId.'/protocol-day';
        $body = ['protocol_id' => $this->protocol->id, 'date' => '2026-11-03', 'item_id' => $this->item->id,
            'done' => true, 'timezone' => 'Europe/Amsterdam'];
        $this->postJson($url, $body)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.done', true);
        $this->withHeader('X-Test-Modern', '1')->postJson($url, [...$body, 'administration' => 2, 'done' => false])
            ->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('items.0.done', true)->assertJsonPath('items.1.done', false);
        $this->withHeader('X-Test-Modern', '')->getJson($url.'?'.http_build_query($body))
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.done', false);
    }

    public function test_legacy_preparation_explains_therapist_start_and_get_never_starts(): void
    {
        $this->protocol->update(['started_at' => null]);
        $this->getJson('/api/horses/'.$this->horseId.'/dashboard')->assertOk()
            ->assertJsonPath('protocol.notifications.0.type', 'preparation')
            ->assertJsonPath('protocol.notifications.0.items.0.dosage', '140 g totaal');
        $this->assertNull($this->protocol->fresh()->started_at);
    }

    public function test_catalog_frequency_edits_refresh_the_legacy_daily_check_without_rewriting_history(): void
    {
        $definition = $this->item->phase->phase;
        $catalog = $definition->supplements()->create(['name' => 'Herb', 'supplement_type' => 'kruid', 'administrations_per_day' => 2]);
        $this->item->update(['supplement_id' => $catalog->id]);
        $morning = $this->operation();
        $this->upload([$morning], true)->assertOk();
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'compat@example.test', 'password' => 'password', 'role' => 'owner', 'active' => true]);
        $body = ['protocol_template_phase_id' => $definition->id, 'name' => 'Herb', 'supplement_type' => 'kruid', 'administrations_per_day' => 1];
        $this->actingAs($admin, 'admin')->put('/admin/protocol-settings/supplements/'.$catalog->id, $body)->assertSessionHasNoErrors();
        $this->assertTrue((bool) $this->item->intakes()->where('id', $morning['id'])->value('day_done'));
        $this->put('/admin/protocol-settings/supplements/'.$catalog->id, [...$body, 'administrations_per_day' => 2])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $this->item->intakes()->where('id', $morning['id'])->value('day_done'));
        $this->assertTrue($this->item->intakes()->where('id', $morning['id'])->firstOrFail()->done);
    }

    public function test_migration_never_marks_missing_doses_done_and_honors_frozen_days(): void
    {
        foreach (['2026-11-04', '2026-11-03'] as $date) {
            $this->item->intakes()->create(['horse_id' => $this->horseId, 'date' => $date, 'administration' => 1, 'done' => true]);
        }
        DB::table('protocol_day_snapshots')->insert(['protocol_id' => $this->protocol->id, 'date' => '2026-11-03',
            'items' => json_encode([['id' => $this->item->id, 'administration' => 1, 'done' => true]]), 'created_at' => now(), 'updated_at' => now()]);
        $this->item->intakes()->create(['horse_id' => $this->horseId, 'date' => '2026-11-02', 'administration' => 2, 'done' => true]);
        $migration = require database_path('migrations/2026_11_04_000001_add_day_done_to_protocol_supplement_intakes.php');
        $migration->down();
        $migration->up();
        $this->assertFalse((bool) $this->item->intakes()->whereDate('date', '2026-11-04')->value('day_done'));
        $this->assertTrue((bool) $this->item->intakes()->whereDate('date', '2026-11-03')->value('day_done'));
        $morning = $this->item->intakes()->whereDate('date', '2026-11-02')->where('administration', 1)->firstOrFail();
        $this->assertFalse($morning->done);
        $this->assertFalse((bool) $morning->day_done);
    }

    public function test_legacy_retries_and_a_second_device_put_do_not_duplicate_daily_rows(): void
    {
        $first = $this->operation();
        unset($first['data']['administration']);
        $second = $this->operation();
        unset($second['data']['administration']);
        $this->upload([$first])->assertOk();
        $this->upload([$first, $second])->assertOk();
        $this->assertSame(2, $this->item->intakes()->count());
        $this->upload([['op' => 'PATCH', 'type' => 'protocol_supplement_intakes', 'id' => $second['id'], 'data' => ['done' => false]]])->assertOk();
        $this->assertSame([false, false], $this->item->intakes()->orderBy('administration')->pluck('done')->all());
        $this->upload([$second])->assertOk();
        $this->upload([['op' => 'DELETE', 'type' => 'protocol_supplement_intakes', 'id' => $first['id']]], true)->assertOk();
        $this->upload([['op' => 'DELETE', 'type' => 'protocol_supplement_intakes', 'id' => $second['id']]])->assertOk();
        $this->assertSame([false, false], $this->item->intakes()->orderBy('administration')->pluck('done')->all());
    }

    public function test_modern_offline_morning_put_merges_with_projection_row_and_alias_cannot_move_its_identity(): void
    {
        $this->upload([$this->operation(2)], true)->assertOk();
        $morning = $this->operation();
        $this->upload([$morning], true)->assertOk();
        $this->assertSame([true, true], $this->item->intakes()->orderBy('administration')->pluck('done')->all());
        $this->upload([['op' => 'PATCH', 'type' => 'protocol_supplement_intakes', 'id' => $morning['id'], 'data' => ['done' => false]]], true)->assertOk();
        $this->assertSame([false, true], $this->item->intakes()->orderBy('administration')->pluck('done')->all());
        $this->upload([['op' => 'PATCH', 'type' => 'protocol_supplement_intakes', 'id' => $morning['id'],
            'data' => ['date' => '2026-11-03', 'done' => true]]], true)->assertStatus(422);
        $foreign = Horse::create(['owner_id' => User::factory()->create()->id, 'name' => 'Someone else', 'status' => 'active']);
        $this->upload([['op' => 'PATCH', 'type' => 'protocol_supplement_intakes', 'id' => $morning['id'],
            'data' => ['horse_id' => $foreign->id, 'done' => true]]], true)->assertStatus(403);
    }
}
