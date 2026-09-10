<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminBookingDeletionTest extends TestCase
{
    use RefreshDatabase;

    private IntakeBooking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(AdminUser::create(['name' => 'Owner', 'email' => 'booking-delete@example.test',
            'password' => 'password', 'role' => 'owner', 'active' => true]), 'admin');
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $therapist = Therapist::create(['name' => 'Therapist']);
        $this->booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id,
            'therapist_id' => $therapist->id, 'scheduled_at' => now(), 'status' => 'confirmed']);
    }

    public function test_delete_removes_only_the_booking_and_updates_the_overview(): void
    {
        $other = $this->booking->replicate();
        $other->save();
        $this->from('/admin/bookings/'.$this->booking->id)
            ->delete('/admin/bookings/'.$this->booking->id, ['confirm_delete' => true])
            ->assertRedirect('/admin/bookings')->assertSessionHasNoErrors();
        $this->assertModelMissing($this->booking);
        $this->assertModelExists($other);
        $this->assertModelExists($this->booking->user);
        $this->assertModelExists($this->booking->horse);
        $this->assertModelExists($this->booking->therapist);
        $this->assertDatabaseHas('audit_logs', ['action' => 'deleted', 'target_type' => 'IntakeBooking', 'target_id' => $this->booking->id]);
        $this->get('/admin/bookings')->assertInertia(fn (Assert $page) => $page->has('bookings.data', 1)->where('counts.confirmed', 1));
    }

    public function test_delete_requires_confirmation(): void
    {
        $this->delete('/admin/bookings/'.$this->booking->id)->assertSessionHasErrors('confirm_delete');
        $this->assertModelExists($this->booking);
    }

    public function test_delete_failure_rolls_back_and_returns_a_form_error(): void
    {
        AuditLog::creating(fn () => throw new \RuntimeException('Simulated failure'));
        try {
            $this->from('/admin/bookings')->delete('/admin/bookings/'.$this->booking->id, ['confirm_delete' => true])
                ->assertRedirect('/admin/bookings')->assertSessionHasErrors('booking_delete');
            $this->assertModelExists($this->booking);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_guest_cannot_delete_bookings(): void
    {
        auth('admin')->logout();
        $this->delete('/admin/bookings/'.$this->booking->id, ['confirm_delete' => true])->assertRedirect('/admin/login');
        $this->assertModelExists($this->booking);
    }
}
