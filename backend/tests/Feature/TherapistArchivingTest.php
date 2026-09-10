<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\LibraryItem;
use App\Models\Protocol;
use App\Models\ProtocolTemplate;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TherapistArchivingTest extends TestCase
{
    use RefreshDatabase;

    private Therapist $therapist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(AdminUser::create(['name' => 'Owner', 'email' => 'archive@example.test',
            'password' => 'password', 'role' => 'owner', 'active' => true]), 'admin');
        $this->therapist = Therapist::create(['name' => 'Archive Author', 'title' => 'Therapist']);
    }

    public function test_legacy_delete_archives_and_preserves_bookings_and_library_authorship(): void
    {
        $booking = IntakeBooking::create(['user_id' => User::factory()->create()->id,
            'therapist_id' => $this->therapist->id, 'scheduled_at' => now(), 'status' => 'confirmed']);
        $horse = Horse::create(['owner_id' => $booking->user_id, 'name' => 'Historical horse', 'status' => 'active']);
        $template = ProtocolTemplate::create(['name' => 'Historical template']);
        $protocol = Protocol::create(['protocol_template_id' => $template->id, 'protocol_template_name' => $template->name, 'horse_id' => $horse->id, 'therapist_id' => $this->therapist->id, 'title' => 'History', 'status' => 'active']);
        $item = LibraryItem::create(['title' => 'Historical video', 'slug' => 'historical', 'format' => 'video',
            'author_therapist_id' => $this->therapist->id]);
        $this->from('/admin/therapists')->delete('/admin/therapists/'.$this->therapist->id)
            ->assertRedirect('/admin/therapists')->assertSessionHasNoErrors();
        $this->assertNotNull($this->therapist->fresh()->archived_at);
        $this->assertSame($this->therapist->id, $booking->fresh()->therapist_id);
        $this->assertSame('Archive Author', $booking->fresh()->therapist->name);
        $this->assertSame('Archive Author', $item->fresh()->author->name);
        $this->assertSame('Archive Author', $protocol->fresh()->therapist->name);
        $this->get('/admin/bookings/'.$booking->id)->assertInertia(fn (Assert $p) => $p->where('booking.therapist.name', 'Archive Author'));
    }

    public function test_archived_filter_and_restore_and_repeated_archive_are_safe(): void
    {
        $url = '/admin/therapists/'.$this->therapist->id;
        $this->post($url.'/archive')->assertRedirect()->assertSessionHasNoErrors();
        $date = $this->therapist->fresh()->archived_at;
        $this->post($url.'/archive')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals($date, $this->therapist->fresh()->archived_at);
        $this->get('/admin/therapists')->assertInertia(fn (Assert $p) => $p->has('therapists', 0));
        $this->get('/admin/therapists?archived=1')->assertInertia(fn (Assert $p) => $p->has('therapists', 1));
        $this->get('/admin/library/create')->assertInertia(fn (Assert $p) => $p->has('therapists', 0));
        $this->post($url.'/restore')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->therapist->fresh()->archived_at);
        $this->get('/admin/library/create')->assertInertia(fn (Assert $p) => $p->has('therapists', 1));
    }

    public function test_existing_author_can_be_retained_but_not_assigned_to_new_content(): void
    {
        $item = LibraryItem::create(['title' => 'Article', 'slug' => 'article', 'format' => 'article', 'author_therapist_id' => $this->therapist->id]);
        $this->post('/admin/therapists/'.$this->therapist->id.'/archive')->assertRedirect();
        $data = ['title' => 'Updated', 'format' => 'article', 'author_therapist_id' => $this->therapist->id];
        $this->post('/admin/library', $data)->assertSessionHasErrors('author_therapist_id');
        $this->put('/admin/library/'.$item->id, $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->get('/admin/library/'.$item->id.'/edit')->assertInertia(fn (Assert $p) => $p
            ->where('therapists.0.id', $this->therapist->id)->where('therapists.0.archived_at', fn ($date) => $date !== null));
    }

    public function test_a_storage_failure_rolls_back_and_returns_a_form_error(): void
    {
        AuditLog::creating(fn () => throw new \RuntimeException('Simulated storage failure'));
        try {
            $this->from('/admin/therapists')->post('/admin/therapists/'.$this->therapist->id.'/archive')
                ->assertRedirect('/admin/therapists')->assertSessionHasErrors('archive');
            $this->assertNull($this->therapist->fresh()->archived_at);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_archived_therapist_cannot_be_newly_assigned_to_a_booking(): void
    {
        $active = Therapist::create(['name' => 'Active therapist']);
        $booking = IntakeBooking::create(['user_id' => User::factory()->create()->id,
            'therapist_id' => $active->id, 'scheduled_at' => now(), 'status' => 'confirmed']);
        $this->post('/admin/therapists/'.$this->therapist->id.'/archive')->assertRedirect();
        $this->put('/admin/bookings/'.$booking->id, ['scheduled_at' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 30, 'therapist_id' => $this->therapist->id])->assertSessionHasErrors('therapist_id');
        $this->assertSame($active->id, $booking->fresh()->therapist_id);
    }

    public function test_unauthorised_role_cannot_archive_or_restore(): void
    {
        $this->actingAs(AdminUser::create(['name' => 'Editor', 'email' => 'archive-editor@example.test',
            'password' => 'password', 'role' => 'content_editor', 'active' => true]), 'admin');
        $this->post('/admin/therapists/'.$this->therapist->id.'/archive')->assertForbidden();
        $this->post('/admin/therapists/'.$this->therapist->id.'/restore')->assertForbidden();
        $this->assertNull($this->therapist->fresh()->archived_at);
    }
}
