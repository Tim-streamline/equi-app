<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\Protocol;
use App\Models\ProtocolManualAdvies;
use App\Models\ProtocolTemplate;
use App\Models\ProtocolTemplatePhase;
use App\Models\User;
use App\Support\HorseDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProtocolManualAdviceTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Horse $horse;

    private ProtocolTemplate $template;

    private ProtocolTemplatePhase $start;

    private ProtocolTemplatePhase $buildUp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::query()->create(['name' => 'Admin', 'email' => 'manual-advice@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $owner = User::factory()->create();
        $this->horse = Horse::query()->create(['owner_id' => $owner->id, 'name' => 'Boaz', 'status' => 'active']);
        IntakeBooking::create(['horse_id' => $this->horse->id, 'user_id' => $owner->id, 'submitted_at' => now()]);
        $this->template = ProtocolTemplate::query()->create(['name' => 'Herstel']);
        $this->start = $this->template->phases()->create(['order' => 1, 'name' => 'Opstart', 'required' => true]);
        $this->buildUp = $this->template->phases()->create(['order' => 2, 'name' => 'Opbouw', 'required' => false]);
        foreach ([$this->start, $this->buildUp] as $phase) {
            $phase->weeks()->create(['number' => 1]);
            $phase->weeks()->create(['number' => 2]);
        }
        $this->actingAs($this->admin, 'admin');
    }

    public function test_manual_advice_is_saved_with_the_protocol_and_linked_to_new_phases(): void
    {
        $this->post('/admin/protocols', $this->payload(manual: [
            $this->manual('voeding', 'Hooi weken', action: 'avoid'),
            $this->manual('management', 'Paddock afrasteren', phases: ['new-opbouw'], extra: ['management_category' => 'environment']),
            $this->manual('voeding', 'Zout erbij'),
        ]))->assertSessionHasNoErrors();

        $protocol = $this->protocol();
        $rows = $protocol->manualAdviezen()->with('phases')->get();
        $this->assertSame([['management', 1], ['voeding', 1], ['voeding', 2]], $rows->map(fn ($row) => [$row->category, $row->sort_order])->all());
        $hay = $rows->firstWhere('title', 'Hooi weken');
        // Nutrition advice has no action; it is always a recommendation.
        $this->assertSame(['do', null, 'protocol'], [$hay->action, $hay->management_category, $hay->applies_to]);
        $paddock = $rows->firstWhere('title', 'Paddock afrasteren');
        $this->assertSame(['Opbouw'], $paddock->phases->pluck('title')->all());
        $this->assertSame('phases', $paddock->applies_to);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $protocol->id, 'action' => 'updated', 'reason' => 'Handmatige adviezen']);
    }

    public function test_manual_advice_is_edited_relinked_and_removed_on_update(): void
    {
        $protocol = $this->savedProtocol([
            $this->manual('beweging', 'Stappen'),
            $this->manual('beweging', 'Longeren', phases: ['new-opbouw']),
        ]);
        $phases = $protocol->phases()->orderBy('order')->get();
        [$stappen, $longeren] = [$protocol->manualAdviezen->firstWhere('title', 'Stappen'), $protocol->manualAdviezen->firstWhere('title', 'Longeren')];

        $this->put("/admin/protocols/{$protocol->id}", $this->payload($protocol, [
            ['id' => strtoupper($stappen->id), 'client_key' => $stappen->id] + $this->manual('beweging', 'Dagelijks stappen', phases: [$phases[0]->id], action: 'avoid'),
            $this->manual('beweging', 'Nieuw advies'),
        ]))->assertSessionHasNoErrors();

        $rows = $protocol->manualAdviezen()->with('phases')->get();
        $this->assertSame(['Dagelijks stappen', 'Nieuw advies'], $rows->pluck('title')->all());
        $this->assertSame($stappen->id, $rows[0]->id);
        $this->assertSame(['avoid', [$phases[0]->id]], [$rows[0]->action, $rows[0]->phases->pluck('id')->all()]);
        $this->assertModelMissing($longeren);
        $this->assertSame(0, DB::table('protocol_manual_advies_phase')->where('protocol_manual_advies_id', $longeren->id)->count());
    }

    public function test_omitting_manual_advice_keeps_it_and_an_empty_list_removes_it(): void
    {
        $protocol = $this->savedProtocol([$this->manual('voeding', 'Blijft')]);
        $payload = $this->payload($protocol);
        unset($payload['manual_advice']);

        $this->put("/admin/protocols/{$protocol->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, $protocol->manualAdviezen()->count());

        $this->put("/admin/protocols/{$protocol->id}", $this->payload($protocol, []))->assertSessionHasNoErrors();
        $this->assertSame(0, $protocol->manualAdviezen()->count());
    }

    public function test_phase_advice_needs_a_phase_that_is_in_the_plan(): void
    {
        $protocol = $this->savedProtocol([$this->manual('beweging', 'Longeren', phases: ['new-opbouw'])]);
        $advice = $protocol->manualAdviezen()->sole();
        $opbouw = $protocol->phases()->where('title', 'Opbouw')->sole();

        // The editor removed the phase and kept the advice limited to phases.
        $payload = $this->payload($protocol, [['id' => $advice->id, 'client_key' => $advice->id] + $this->manual('beweging', 'Longeren', appliesTo: 'phases')]);
        $payload['phases'] = array_values(array_filter($payload['phases'], fn ($phase) => $phase['id'] !== $opbouw->id));
        $this->put("/admin/protocols/{$protocol->id}", $payload)
            ->assertSessionHasErrors(['manual_advice.0.phase_keys' => 'Kies minimaal één fase of laat het advies voor het hele protocol gelden.']);

        $payload['manual_advice'][0]['phase_keys'] = [$opbouw->id];
        $this->put("/admin/protocols/{$protocol->id}", $payload)->assertSessionHasErrors('manual_advice.0.phase_keys.0');

        $this->assertModelExists($opbouw);
        $this->assertSame([$opbouw->id], $advice->phases()->pluck('protocol_phases.id')->all());
    }

    public function test_several_advices_share_a_phase_and_phase_removal_is_audited(): void
    {
        $protocol = $this->savedProtocol([
            $this->manual('voeding', 'Eén', phases: ['new-opbouw', 'new-opbouw', 'new-opstart']),
            $this->manual('beweging', 'Twee', phases: ['new-opbouw']),
        ]);
        $this->assertSame([2, 1], $protocol->manualAdviezen->sortBy('title')->map(fn ($advice) => $advice->phases()->count())->values()->all());

        // An editor from before manual advice existed leaves it out; removing a phase still changes its scope.
        $payload = $this->payload($protocol);
        unset($payload['manual_advice']);
        $payload['phases'] = array_values(array_filter($payload['phases'], fn ($phase) => $phase['client_key'] !== $protocol->phases()->where('title', 'Opbouw')->value('id')));
        DB::table('audit_logs')->delete();
        $this->put("/admin/protocols/{$protocol->id}", $payload)->assertSessionHasNoErrors();

        $log = DB::table('audit_logs')->where('reason', 'Handmatige adviezen')->sole();
        $before = collect(json_decode($log->before, true)['manual_advice'])->keyBy('title');
        $after = collect(json_decode($log->after, true)['manual_advice'])->keyBy('title');
        $this->assertCount(2, $before['Eén']['phase_ids']);
        $this->assertCount(1, $after['Eén']['phase_ids']);
        $this->assertSame([], $after['Twee']['phase_ids']);
    }

    public function test_invalid_manual_advice_is_rejected(): void
    {
        $this->post('/admin/protocols', $this->payload(manual: [
            $this->manual('management', ''),
            ['management_category' => null] + $this->manual('management', 'Zonder onderdeel'),
            $this->manual('onbekend', 'Categorie'),
            $this->manual('voeding', 'Te lang', extra: ['description' => str_repeat('x', 10001)]),
        ]))->assertSessionHasErrors([
            'manual_advice.0.title', 'manual_advice.1.management_category', 'manual_advice.2.category', 'manual_advice.3.description',
        ]);
        $this->assertSame(0, ProtocolManualAdvies::count());
    }

    public function test_advice_of_another_protocol_or_already_removed_is_not_recreated(): void
    {
        $protocol = $this->savedProtocol([$this->manual('voeding', 'Eigen advies')]);
        $other = Protocol::create(['horse_id' => Horse::create(['owner_id' => User::factory()->create()->id, 'name' => 'Ander', 'status' => 'active'])->id, 'title' => 'Ander', 'status' => 'paused']);
        $foreign = $other->manualAdviezen()->create(['category' => 'voeding', 'title' => 'Van een ander', 'description' => 'x']);

        foreach ([$foreign->id, (string) Str::uuid()] as $id) {
            $this->put("/admin/protocols/{$protocol->id}", $this->payload($protocol, [
                ['id' => $id, 'client_key' => $id] + $this->manual('voeding', 'Overgenomen'),
            ]))->assertSessionHasErrors('manual_advice.0.id');
        }

        // The failed saves rolled back: nothing was moved, renamed or removed.
        $this->assertSame(['Eigen advies'], $protocol->manualAdviezen()->pluck('title')->all());
        $this->assertSame('Van een ander', $foreign->fresh()->title);
        $this->assertSame($other->id, $foreign->fresh()->protocol_id);
    }

    public function test_customers_see_phase_advice_once_one_of_its_phases_is_accessible(): void
    {
        $protocol = $this->savedProtocol([
            $this->manual('voeding', 'Altijd'),
            $this->manual('management', 'Bij opbouw', phases: ['new-opbouw'], extra: ['management_category' => 'care']),
            $this->manual('beweging', 'Bij opstart', phases: ['new-opstart']),
        ]);
        $dashboard = app(HorseDashboard::class);
        $titles = fn (array $data) => [
            collect($data['nutrition']['advice'])->where('manual', true)->pluck('title')->all(),
            collect($data['management'])->flatMap(fn ($group) => collect($group['items'])->where('manual', true)->map(fn ($item) => $group['id'].':'.$item['title']))->all(),
            collect($data['movement'])->where('manual', true)->pluck('title')->all(),
        ];

        // Not started: every phase is locked.
        $this->assertSame([['Altijd'], [], []], $titles($dashboard->protocol($protocol->fresh(), CarbonImmutable::now())));

        $started = CarbonImmutable::today('Europe/Amsterdam');
        $protocol->forceFill(['started_at' => $started])->save();
        // Opbouw starts in week 3 and opens a week earlier.
        $this->assertSame([['Altijd'], [], ['Bij opstart']], $titles($dashboard->protocol($protocol->fresh(), $started->addDays(6)->setTime(12, 0))));
        $this->assertSame([['Altijd'], ['care:Bij opbouw'], ['Bij opstart']], $titles($dashboard->protocol($protocol->fresh(), $started->addDays(7)->setTime(12, 0))));
        // Completed phases keep their advice, like their other content.
        $late = $dashboard->protocol($protocol->fresh(), $started->addWeeks(10));
        $this->assertSame([['Altijd'], ['care:Bij opbouw'], ['Bij opstart']], $titles($late));

        $movement = collect($late['movement'])->firstWhere('title', 'Bij opstart');
        $this->assertSame('Fase: Opstart', $movement['phaseLabel']);
        $this->assertSame([$protocol->phases()->where('title', 'Opstart')->value('id')], $movement['phaseIds']);
        $this->assertNull(collect($late['nutrition']['advice'])->firstWhere('title', 'Altijd')['phaseLabel']);
    }

    public function test_the_preview_shows_manual_advice_from_the_form_for_every_phase(): void
    {
        $protocol = $this->savedProtocol([$this->manual('voeding', 'Opgeslagen')]);
        $payload = $this->payload($protocol, [$this->manual('beweging', 'Nog niet opgeslagen', phases: [$protocol->phases()->where('title', 'Opbouw')->value('id')])]);

        $preview = $this->postJson("/admin/protocols/{$protocol->id}/preview", $payload)->assertOk()->json('protocol');
        $this->assertSame(['Nog niet opgeslagen'], collect($preview['movement'])->where('manual', true)->pluck('title')->all());
        $this->assertSame('Fase: Opbouw', collect($preview['movement'])->firstWhere('manual', true)['phaseLabel']);
        $this->assertSame([], collect($preview['nutrition']['advice'])->where('manual', true)->all());

        // Without manual advice in the form, the preview shows what is stored.
        unset($payload['manual_advice']);
        $preview = $this->postJson("/admin/protocols/{$protocol->id}/preview", $payload)->assertOk()->json('protocol');
        $this->assertSame(['Opgeslagen'], collect($preview['nutrition']['advice'])->where('manual', true)->pluck('title')->all());
    }

    public function test_editor_and_detail_page_receive_manual_advice_and_deletion_cascades(): void
    {
        $protocol = $this->savedProtocol([$this->manual('management', 'Paddock', phases: ['new-opbouw'], extra: ['management_category' => 'monitoring'])]);
        $advice = $protocol->manualAdviezen()->sole();
        $opbouw = $protocol->phases()->where('title', 'Opbouw')->value('id');

        $this->get("/admin/protocols/{$protocol->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->where('protocol.manual_adviezen.0.id', $advice->id)
            ->where('protocol.manual_adviezen.0.phases.0.id', $opbouw));
        $this->get("/admin/protocols/{$protocol->id}")->assertInertia(fn (Assert $page) => $page
            ->where('protocol.manual_adviezen.0.title', 'Paddock')
            ->where('protocol.manual_adviezen.0.phases.0.title', 'Opbouw'));

        $protocol->delete();
        $this->assertModelMissing($advice);
        $this->assertSame(0, DB::table('protocol_manual_advies_phase')->count());
    }

    /** @param  array<int, array<string, mixed>>  $manual */
    private function savedProtocol(array $manual): Protocol
    {
        $this->post('/admin/protocols', $this->payload(manual: $manual))->assertSessionHasNoErrors();

        return $this->protocol()->load('manualAdviezen');
    }

    private function protocol(): Protocol
    {
        return Protocol::where('horse_id', $this->horse->id)->whereNotNull('protocol_template_id')->sole();
    }

    /** @return array<string, mixed> */
    private function manual(string $category, string $title, array $phases = [], string $action = 'do', array $extra = [], ?string $appliesTo = null): array
    {
        return [
            'client_key' => 'key-'.Str::random(8),
            'category' => $category,
            'title' => $title,
            'description' => "Omschrijving van {$title}.",
            'management_category' => $category === 'management' ? 'environment' : null,
            'action' => $action,
            'applies_to' => $appliesTo ?? ($phases === [] ? 'protocol' : 'phases'),
            'phase_keys' => $phases,
            ...$extra,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $manual
     * @return array<string, mixed>
     */
    private function payload(?Protocol $protocol = null, ?array $manual = null): array
    {
        $phases = $protocol
            ? $protocol->phases()->with('weeks')->orderBy('order')->get()->map(fn ($phase): array => [
                'id' => $phase->id, 'client_key' => $phase->id, 'protocol_template_phase_id' => $phase->protocol_template_phase_id,
                'week_count' => $phase->weeks->count(), 'supplements' => [],
            ])->all()
            : collect([[$this->start, 'new-opstart'], [$this->buildUp, 'new-opbouw']])->map(fn ($pair): array => [
                'id' => null, 'client_key' => $pair[1], 'protocol_template_phase_id' => $pair[0]->id, 'week_count' => 2, 'supplements' => [],
            ])->all();

        return array_filter([
            'horse_id' => $this->horse->id,
            'protocol_template_id' => $this->template->id,
            'therapist_id' => null,
            'title' => 'Boaz herstelprotocol',
            'status' => 'paused',
            'published' => false,
            'analysis' => ['cause' => null],
            'advice' => [],
            'voeding_advies_ids' => [],
            'management_advies_ids' => [],
            'beweging_advies_ids' => [],
            'phases' => $phases,
            'manual_advice' => $manual,
        ], fn ($value, $key) => $key !== 'manual_advice' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }
}
