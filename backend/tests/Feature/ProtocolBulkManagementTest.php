<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\Protocol;
use App\Models\ProtocolTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProtocolBulkManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'admin'): void
    {
        $this->actingAs(AdminUser::create(['name' => 'Tester', 'email' => 'bulk@example.test', 'password' => 'password', 'role' => $role, 'active' => true]), 'admin');
    }

    private function protocol(string $status = 'active', string $name = 'Horse'): Protocol
    {
        $horse = Horse::create(['name' => $name, 'owner_id' => User::factory()->create()->id, 'status' => 'active']);

        return Protocol::create(['horse_id' => $horse->id, 'protocol_template_id' => ProtocolTemplate::firstOrCreate(['name' => 'Test template'])->id, 'title' => $name.' protocol', 'status' => $status]);
    }

    public function test_only_selected_protocols_are_archived_and_restored_with_their_original_status(): void
    {
        $this->admin();
        $active = $this->protocol();
        $paused = $this->protocol('paused');
        $untouched = $this->protocol('completed');
        $active->phases()->create(['title' => 'Phase', 'order' => 0, 'state' => 'active', 'protocol_template_phase_id' => $active->protocolTemplate->phases()->create(['name' => 'Phase', 'order' => 0])->id]);
        $ids = [$active->id, $paused->id];
        $this->post('/admin/protocols/bulk', ['action' => 'archive', 'ids' => $ids])->assertRedirect();
        $this->assertSame('archived', $active->fresh()->status);
        $this->assertSame('paused', $paused->fresh()->archived_previous_status);
        $this->assertSame('completed', $untouched->fresh()->status);
        $this->assertSame(1, $active->phases()->count());
        $this->post('/admin/protocols/bulk', ['action' => 'archive', 'ids' => $ids])->assertRedirect();
        $this->assertSame('active', $active->fresh()->archived_previous_status);
        $this->get('/admin/protocols')->assertInertia(fn (Assert $page) => $page->has('protocols.data', 1));
        $this->get('/admin/protocols?status=archived')->assertInertia(fn (Assert $page) => $page->has('protocols.data', 2));
        $this->post('/admin/protocols/bulk', ['action' => 'restore', 'ids' => $ids])->assertRedirect();
        $this->assertSame('active', $active->fresh()->status);
        $this->assertSame('paused', $paused->fresh()->status);
        $this->assertNull($paused->fresh()->archived_previous_status);
        $this->assertDatabaseCount('audit_logs', 4);
    }

    public function test_select_all_covers_filtered_results_beyond_the_current_page(): void
    {
        $this->admin();
        $horse = Horse::create(['name' => 'Matched', 'owner_id' => User::factory()->create()->id, 'status' => 'active']);
        foreach (range(1, 23) as $number) {
            Protocol::create(['horse_id' => $horse->id, 'protocol_template_id' => ProtocolTemplate::firstOrCreate(['name' => 'Test template'])->id, 'title' => 'Protocol '.$number, 'status' => 'active']);
        }
        $this->protocol('active', 'Other');
        $this->get('/admin/protocols?q=Matched&status=active')->assertInertia(fn (Assert $page) => $page
            ->has('protocols.data', 20)->has('selectionIds', 23));
        $this->get('/admin/protocols?q=Other&status=active')->assertInertia(fn (Assert $page) => $page->has('selectionIds', 1));
    }

    public function test_deletion_requires_confirmation_and_cascades_only_the_selected_protocol(): void
    {
        $this->admin();
        $selected = $this->protocol();
        $phase = $selected->phases()->create(['title' => 'Phase', 'order' => 0, 'state' => 'active', 'protocol_template_phase_id' => $selected->protocolTemplate->phases()->create(['name' => 'Phase', 'order' => 0])->id]);
        $untouched = $this->protocol();
        $this->postJson('/admin/protocols/bulk', ['action' => 'delete', 'ids' => [$selected->id]])->assertUnprocessable();
        $this->assertModelExists($selected);
        $this->post('/admin/protocols/bulk', ['action' => 'delete', 'ids' => [$selected->id], 'confirmed' => true])->assertRedirect();
        $this->assertModelMissing($selected);
        $this->assertModelMissing($phase);
        $this->assertModelExists($untouched);
        $this->assertDatabaseHas('audit_logs', ['action' => 'deleted', 'target_id' => $selected->id]);
    }

    public function test_invalid_selection_is_atomic_and_mutations_require_a_management_role(): void
    {
        $this->admin();
        $protocol = $this->protocol();
        $this->postJson('/admin/protocols/bulk', ['action' => 'archive', 'ids' => [$protocol->id, '00000000-0000-4000-8000-000000000000']])->assertUnprocessable();
        $this->assertSame('active', $protocol->fresh()->status);
        $this->postJson('/admin/protocols/bulk', ['action' => 'archive', 'ids' => [$protocol->id, $protocol->id]])->assertUnprocessable();
        $admin = auth('admin')->user();
        $admin->update(['role' => 'support']);
        $this->postJson('/admin/protocols/bulk', ['action' => 'archive', 'ids' => [$protocol->id]])->assertForbidden();
        auth('admin')->logout();
        $this->postJson('/admin/protocols/bulk', ['action' => 'archive', 'ids' => [$protocol->id]])->assertUnauthorized();
    }
}
