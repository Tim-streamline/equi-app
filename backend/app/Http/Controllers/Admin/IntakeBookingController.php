<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntakeBooking;
use App\Models\Therapist;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class IntakeBookingController extends Controller
{
    public function index(Request $request): Response
    {
        $bookings = IntakeBooking::query()
            ->when($request->string('status')->toString(), fn ($query, $s) => $query->where('status', $s))
            ->when($request->string('therapist')->toString(), fn ($query, $t) => $query->where('therapist_id', $t))
            ->with('user:id,name,email', 'horse:id,name', 'therapist:id,name')
            ->orderBy('scheduled_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Bookings/Index', [
            'bookings' => $bookings,
            'filters' => $request->only('status', 'therapist'),
            'therapists' => Therapist::orderBy('name')->get(['id', 'name']),
            'counts' => [
                'pending' => IntakeBooking::where('status', 'pending')->count(),
                'confirmed' => IntakeBooking::where('status', 'confirmed')->count(),
            ],
        ]);
    }

    public function show(IntakeBooking $booking): Response
    {
        $booking->load('user:id,name,email', 'horse:id,name,breed', 'therapist:id,name,title');

        return Inertia::render('Bookings/Show', ['booking' => $booking]);
    }

    public function destroy(Request $request, IntakeBooking $booking): RedirectResponse
    {
        $request->validate(['confirm_delete' => ['required', 'accepted']]);

        try {
            DB::transaction(function () use ($booking) {
                $locked = IntakeBooking::query()->lockForUpdate()->findOrFail($booking->id);
                $locked->delete();
                AuditLogger::deleted($locked);
            });
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['booking_delete' => 'De booking kon niet worden verwijderd. Er zijn geen gegevens verwijderd. Probeer het opnieuw.']);
        }

        return redirect()->route('admin.bookings.index')->with('success', 'Booking definitief verwijderd.');
    }

    public function updateStatus(Request $request, IntakeBooking $booking): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', 'in:pending,confirmed,done,cancelled']])['status'];
        $before = $booking->only('status');
        $booking->update(['status' => $status]);
        AuditLogger::updated($booking, $before, $request->input('reason'));

        return back()->with('success', "Booking marked {$status}.");
    }

    public function update(Request $request, IntakeBooking $booking): RedirectResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'therapist_id' => ['required', Therapist::assignmentRule($booking->therapist_id)],
            'notes' => ['nullable', 'string'],
        ]);
        $before = $booking->only(array_keys($data));
        $booking->update($data);
        AuditLogger::updated($booking, $before);

        return back()->with('success', 'Booking rescheduled.');
    }
}
