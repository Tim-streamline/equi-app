<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\ProtocolTemplate;
use App\Models\Supplement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class ProtocolTemplateDuplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(AdminUser::query()->create([
            'name' => 'Template Admin',
            'email' => 'template-copy@example.com',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]), 'admin');
    }

    public function test_copies_the_complete_template_without_sharing_records_or_copying_customer_protocols(): void
    {
        $source = $this->createTemplate();
        $source->load('phases.weeks', 'phases.supplements.weeks');
        $original = $source->fresh(['phases.weeks', 'phases.supplements.weeks'])->toArray();
        $horse = Horse::query()->create(['owner_id' => User::factory()->create()->id, 'name' => 'Nova']);
        $protocol = $source->protocols()->create(['horse_id' => $horse->id, 'title' => 'Existing protocol']);

        $response = $this->post("/admin/protocol-settings/templates/{$source->id}/duplicate");
        $response->assertSessionHasNoErrors()->assertSessionHas('success', 'Protocol template gekopieerd.');

        $copy = ProtocolTemplate::query()->where('name', 'Herstel (kopie)')->firstOrFail();
        $copy->load('phases.weeks', 'phases.supplements.weeks');
        $response->assertRedirect(route('admin.protocol-settings.index', ['template' => $copy->id]));
        $this->get($response->headers->get('Location'))->assertInertia(fn (Assert $page) => $page
            ->component('ProtocolSettings/Index')->where('selectedTemplateId', $copy->id));
        $this->assertNotSame($source->id, $copy->id);
        $this->assertCount(2, $copy->phases);

        foreach ($source->phases as $index => $phase) {
            $copiedPhase = $copy->phases[$index];
            $this->assertNotSame($phase->id, $copiedPhase->id);
            $this->assertSame($copy->id, $copiedPhase->protocol_template_id);
            $this->assertSame($phase->only(['order', 'name', 'description', 'required', 'start_after_previous_phase_weeks']),
                $copiedPhase->only(['order', 'name', 'description', 'required', 'start_after_previous_phase_weeks']));
            $this->assertSame($phase->weeks->pluck('number')->all(), $copiedPhase->weeks->pluck('number')->all());
            $this->assertEmpty($phase->weeks->pluck('id')->intersect($copiedPhase->weeks->pluck('id')));
            $this->assertCount($phase->supplements->count(), $copiedPhase->supplements);

            foreach ($phase->supplements as $supplementIndex => $supplement) {
                $copiedSupplement = $copiedPhase->supplements[$supplementIndex];
                $fields = array_diff($supplement->getFillable(), ['protocol_template_phase_id']);
                $this->assertSame($supplement->only($fields), $copiedSupplement->only($fields));
                $this->assertNotSame($supplement->id, $copiedSupplement->id);
                $this->assertSame($copiedPhase->id, $copiedSupplement->protocol_template_phase_id);
                $this->assertSame($supplement->weeks->pluck('number')->all(), $copiedSupplement->weeks->pluck('number')->all());
                $this->assertEmpty($copiedSupplement->weeks->pluck('id')->diff($copiedPhase->weeks->pluck('id')));
            }
        }

        $this->assertDatabaseCount('protocols', 1);
        $this->assertSame($source->id, $protocol->fresh()->protocol_template_id);
        $this->assertSame(0, $copy->protocols()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'target_type' => 'ProtocolTemplate', 'target_id' => $copy->id]);

        $copy->phases[0]->supplements[0]->update(['instructions' => 'Changed copy']);
        $copy->phases[0]->weeks[0]->delete();
        $this->assertSame($original, $source->fresh(['phases.weeks', 'phases.supplements.weeks'])->toArray());
    }

    public function test_can_copy_an_empty_template_repeatedly_with_unique_names(): void
    {
        $source = ProtocolTemplate::query()->create(['name' => 'Empty']);
        foreach (['Empty (kopie)', 'Empty (kopie 2)'] as $name) {
            $this->post("/admin/protocol-settings/templates/{$source->id}/duplicate")->assertSessionHasNoErrors()->assertRedirect();
            $copy = ProtocolTemplate::query()->where('name', $name)->firstOrFail();
            $this->assertSame(0, $copy->phases()->count());
        }
        $this->assertDatabaseCount('protocol_templates', 3);
    }

    public function test_copy_names_fit_the_name_limit_including_the_suffix(): void
    {
        $source = ProtocolTemplate::query()->create(['name' => str_repeat('é', 255)]);
        foreach ([' (kopie)', ' (kopie 2)'] as $suffix) {
            $this->post("/admin/protocol-settings/templates/{$source->id}/duplicate")->assertSessionHasNoErrors()->assertRedirect();
            $this->assertDatabaseHas('protocol_templates', ['name' => str_repeat('é', 255 - mb_strlen($suffix)).$suffix]);
        }
    }

    public function test_a_failure_during_copy_rolls_back_the_entire_template(): void
    {
        $source = $this->createTemplate();
        $before = ProtocolTemplate::with('phases.weeks', 'phases.supplements.weeks')->get()->toArray();
        Supplement::creating(function (): void {
            throw new RuntimeException('Simulated copy failure');
        });

        try {
            $this->post("/admin/protocol-settings/templates/{$source->id}/duplicate")->assertStatus(500);
        } finally {
            Supplement::flushEventListeners();
        }

        $this->assertSame($before, ProtocolTemplate::with('phases.weeks', 'phases.supplements.weeks')->get()->toArray());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_support_cannot_copy_a_template(): void
    {
        $source = $this->createTemplate();
        $support = AdminUser::query()->create([
            'name' => 'Support', 'email' => 'copy-support@example.com', 'password' => 'password', 'role' => 'support', 'active' => true,
        ]);
        $this->actingAs($support, 'admin')->post("/admin/protocol-settings/templates/{$source->id}/duplicate")->assertForbidden();
        $this->assertDatabaseCount('protocol_templates', 1);
    }

    public function test_missing_template_returns_not_found(): void
    {
        $this->post('/admin/protocol-settings/templates/00000000-0000-4000-8000-000000000000/duplicate')->assertNotFound();
        $this->assertDatabaseCount('protocol_templates', 0);
    }

    private function createTemplate(): ProtocolTemplate
    {
        $template = ProtocolTemplate::query()->create(['name' => 'Herstel']);
        foreach ([null, 0] as $index => $delay) {
            $phase = $template->phases()->create([
                'order' => $index + 1, 'name' => 'Fase '.($index + 1), 'description' => 'Beschrijving',
                'required' => $index === 0, 'start_after_previous_phase_weeks' => $delay,
            ]);
            $weeks = collect([1, 2, 3])->map(fn ($number) => $phase->weeks()->create(['number' => $number]));
            $supplement = $phase->supplements()->create([
                'name' => 'Psyllium', 'description' => 'Beschrijving', 'instructions' => 'Meng met water.',
                'supplement_type' => 'kruid', 'dosis_type' => 'vast', 'dosis' => 20, 'unit' => 'g',
                'add_by_default' => true, 'max_aantal_in_fase' => 2, 'min_aantal_per_week' => 5, 'rust_periode_in_weken' => 1,
            ]);
            $supplement->weeks()->attach([$weeks[0]->id, $weeks[2]->id]);
            $phase->supplements()->create(['name' => 'Zinc', 'supplement_type' => 'mineraal', 'add_by_default' => false]);
        }

        return $template;
    }
}
