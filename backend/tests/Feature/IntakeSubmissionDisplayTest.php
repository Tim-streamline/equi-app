<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\User;
use App\Support\IntakeAnswersPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IntakeSubmissionDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_filename_uses_intake_horse_name_submission_date_and_safe_case_preserving_characters(): void
    {
        $booking = $this->booking(['intake_status' => 'submitted', 'submitted_at' => '2026-09-29 22:24:00']);
        $booking->answers()->create(['section_id' => 'paard', 'field_id' => 'naam', 'value' => json_encode('Rolo')]);
        $pdf = app(IntakeAnswersPdf::class);
        $this->assertSame('EquiApp_protocolintake_30-09-2026_Rolo.pdf', $pdf->filename($booking));
        $this->travel(10)->days();
        $this->assertSame('EquiApp_protocolintake_30-09-2026_Rolo.pdf', $pdf->filename($booking->fresh()));
        $booking->answers()->where('field_id', 'naam')->update(['value' => json_encode("Lúna / .. \\\"\r\npaard")]);
        $this->assertSame('EquiApp_protocolintake_30-09-2026_Luna_paard.pdf', $pdf->filename($booking->fresh()));
        $booking->answers()->delete();
        $this->assertSame('EquiApp_protocolintake_30-09-2026_Profile_name.pdf', $pdf->filename($booking->fresh()));
        $booking->update(['submitted_at' => null]);
        $this->assertSame('EquiApp_protocolintake_datum-onbekend_Profile_name.pdf', $pdf->filename($booking->fresh()));
    }

    public function test_index_and_detail_show_submission_time_in_dutch_timezone_and_preserve_status_filters(): void
    {
        $draft = $this->booking(['scheduled_at' => '2026-10-01 12:00:00']);
        $older = $this->booking(['intake_status' => 'submitted', 'submitted_at' => '2026-09-28 10:00:00', 'status' => 'done']);
        $submitted = $this->booking(['intake_status' => 'submitted', 'submitted_at' => '2026-09-29 22:24:00', 'scheduled_at' => null]);
        $this->actingAs(AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => 'owner', 'active' => true]), 'admin');
        $this->get('/admin/bookings')->assertInertia(fn (Assert $page) => $page
            ->where('bookings.data.0.id', $submitted->id)
            ->where('bookings.data.0.submitted_at_label', '30-09-2026 00:24')
            ->where('bookings.data.0.status_label', 'Te beoordelen')
            ->where('bookings.data.1.id', $older->id)
            ->where('bookings.data.1.status_label', 'done')
            ->where('bookings.data.2.id', $draft->id)
            ->where('bookings.data.2.submitted_at_label', 'Nog niet ingediend'));
        $this->get('/admin/bookings?status=done')->assertInertia(fn (Assert $page) => $page->has('bookings.data', 1)->where('bookings.data.0.id', $older->id));
        $this->get('/admin/bookings/'.$submitted->id)->assertInertia(fn (Assert $page) => $page->where('booking.submitted_at_label', '30-09-2026 00:24')->where('booking.status_label', 'Te beoordelen'));
        $submitted->update(['submitted_at' => null]);
        $this->assertSame('Datum onbekend', $submitted->fresh()->submitted_at_label);
        $submitted->update(['submitted_at' => '2026-12-01 23:24:00']);
        $this->assertSame('02-12-2026 00:24', $submitted->fresh()->submitted_at_label);
    }

    private function booking(array $data): IntakeBooking
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Profile name']);

        return IntakeBooking::create($data + ['user_id' => $user->id, 'horse_id' => $horse->id]);
    }
}
