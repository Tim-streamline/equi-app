<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\ChatSession;
use App\Models\DataExport;
use App\Models\Horse;
use App\Models\IntakeResponse;
use App\Models\ProtocolTemplate;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminHorseActionsTest extends TestCase
{
    use RefreshDatabase;

    private Horse $horse;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(AdminUser::create(['name' => 'Owner', 'email' => 'horse-actions@example.test',
            'password' => 'password', 'role' => 'owner', 'active' => true]), 'admin');
        $this->owner = User::factory()->create();
        $this->horse = Horse::create(['owner_id' => $this->owner->id, 'name' => 'Nova', 'status' => 'active']);
    }

    private function linkedData(): array
    {
        $therapist = Therapist::create(['name' => 'Therapist']);
        $template = ProtocolTemplate::create(['name' => 'Template']);
        $protocol = $this->horse->protocols()->create(['title' => 'Protocol', 'protocol_template_id' => $template->id,
            'protocol_template_name' => $template->name, 'status' => 'active']);
        $observation = $this->horse->observations()->create(['author_id' => $this->owner->id, 'date' => today(), 'note' => 'History']);
        $photo = $observation->photos()->create(['url' => 'https://example.test/photo.jpg']);
        $share = $this->horse->shares()->create(['grantee_user_id' => $this->owner->id, 'role' => 'viewer']);
        $booking = $this->horse->intakeBookings()->create(['user_id' => $this->owner->id,
            'therapist_id' => $therapist->id, 'scheduled_at' => now(), 'status' => 'confirmed']);
        $scan = $this->horse->scans()->create(['user_id' => $this->owner->id, 'scanned_at' => now(), 'score' => 90, 'rating' => 'Goed']);
        $chat = ChatSession::create(['user_id' => $this->owner->id, 'horse_id' => $this->horse->id, 'started_at' => now()]);
        $intake = IntakeResponse::create(['user_id' => $this->owner->id, 'horse_id' => $this->horse->id, 'started_at' => now()]);
        $export = DataExport::create(['user_id' => $this->owner->id, 'horse_id' => $this->horse->id, 'format' => 'pdf', 'requested_at' => now()]);

        return compact('chat', 'intake', 'export', 'protocol', 'observation', 'photo', 'share', 'booking', 'scan', 'therapist', 'template');
    }

    public function test_archive_needs_no_reason_and_filters_active_horses_without_losing_data(): void
    {
        $linked = $this->linkedData();
        $this->from('/admin/horses')->post('/admin/horses/'.$this->horse->id.'/archive')
            ->assertRedirect('/admin/horses')->assertSessionHasNoErrors();
        $this->assertSame('archived', $this->horse->fresh()->status);
        $this->assertNull($this->horse->fresh()->archived_note);
        $this->assertSame($this->horse->id, $linked['booking']->fresh()->horse_id);
        $this->assertNotNull($linked['protocol']->fresh());
        $this->get('/admin/horses')->assertInertia(fn (Assert $p) => $p->has('horses.data', 0));
        $this->get('/admin/horses?status=archived')->assertInertia(fn (Assert $p) => $p->has('horses.data', 1));
        $this->post('/admin/horses/'.$this->horse->id.'/restore')->assertSessionHasNoErrors();
        $this->assertSame('active', $this->horse->fresh()->status);
        $this->get('/admin/horses')->assertInertia(fn (Assert $p) => $p->has('horses.data', 1));
    }

    public function test_delete_checks_links_cascades_owned_data_and_detaches_account_history(): void
    {
        $linked = $this->linkedData();
        $other = Horse::create(['owner_id' => $this->owner->id, 'name' => 'Other', 'status' => 'active']);
        $otherObservation = $other->observations()->create(['author_id' => $this->owner->id, 'date' => today()]);
        $this->getJson('/admin/horses/'.$this->horse->id.'/deletion-preview')
            ->assertOk()->assertJsonPath('removed.protocols.count', 1)->assertJsonPath('detached.bookings.count', 1);
        $this->delete('/admin/horses/'.$this->horse->id, ['confirm_delete' => true])
            ->assertRedirect('/admin/horses')->assertSessionHasNoErrors();
        $this->assertNull($this->horse->fresh());
        foreach (['protocol', 'observation', 'photo', 'share'] as $key) {
            $this->assertNull($linked[$key]->fresh());
        }
        foreach (['booking', 'scan', 'chat', 'intake', 'export'] as $key) {
            $this->assertNull($linked[$key]->fresh()->horse_id);
        }
        $this->assertNotNull($otherObservation->fresh());
        $this->assertNotNull($this->owner->fresh());
        $this->assertNotNull($linked['therapist']->fresh());
        $this->assertNotNull($linked['template']->fresh());
    }

    public function test_failed_delete_rolls_back_all_changes_and_returns_a_form_error(): void
    {
        $linked = $this->linkedData();
        AuditLog::creating(fn () => throw new \RuntimeException('Simulated audit failure'));
        try {
            $this->from('/admin/horses/'.$this->horse->id)->delete('/admin/horses/'.$this->horse->id, ['confirm_delete' => true])
                ->assertRedirect('/admin/horses/'.$this->horse->id)->assertSessionHasErrors('horse_action');
            $this->assertNotNull($this->horse->fresh());
            $this->assertNotNull($linked['observation']->fresh());
            $this->assertSame($this->horse->id, $linked['booking']->fresh()->horse_id);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_failed_archive_rolls_back_and_returns_a_form_error(): void
    {
        AuditLog::creating(fn () => throw new \RuntimeException('Simulated audit failure'));
        try {
            $this->from('/admin/horses')->post('/admin/horses/'.$this->horse->id.'/archive')
                ->assertRedirect('/admin/horses')->assertSessionHasErrors('horse_action');
            $this->assertSame('active', $this->horse->fresh()->status);
            $this->assertNull($this->horse->fresh()->archived_at);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_delete_requires_confirmation(): void
    {
        $this->delete('/admin/horses/'.$this->horse->id)->assertSessionHasErrors('confirm_delete');
        $this->assertNotNull($this->horse->fresh());
    }
}
