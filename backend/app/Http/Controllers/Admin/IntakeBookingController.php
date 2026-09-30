<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntakeBooking;
use App\Models\Therapist;
use App\Support\AuditLogger;
use App\Support\IntakeAnswersPdf;
use App\Support\IntakeReview;
use Illuminate\Http\JsonResponse;
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
            ->orderByRaw('submitted_at DESC NULLS LAST')->orderByDesc('created_at')->orderBy('id')
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

    public function show(Request $request, IntakeBooking $booking): Response
    {
        $booking->load('user:id,name,email', 'horse:id,name,breed', 'therapist:id,name,title');

        return Inertia::render('Bookings/Show', ['booking' => $booking, 'printMode' => $request->boolean('print'), 'review' => app(IntakeReview::class)->forBooking($booking)]);
    }

    public function answersPdf(IntakeBooking $booking, IntakeAnswersPdf $pdf): \Illuminate\Http\Response
    {
        return response($pdf->render($booking), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($booking).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function review(Request $request, IntakeBooking $booking): JsonResponse
    {
        $data = $request->validate([
            'accepted_triggers' => ['sometimes', 'array', 'max:1000'],
            'accepted_triggers.*' => ['string', 'distinct', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:50000'],
        ]);
        if (array_key_exists('accepted_triggers', $data)) {
            $valid = array_column(app(IntakeReview::class)->forBooking($booking)['triggers'], 'id');
            abort_if(array_diff($data['accepted_triggers'], $valid), 422, 'Een protocol-trigger is niet meer van toepassing. Vernieuw de pagina.');
            $booking->accepted_triggers = $data['accepted_triggers'];
        }
        if (array_key_exists('notes', $data)) {
            $booking->review_notes = $data['notes'];
        }
        $booking->review_updated_at = now();
        $booking->save();

        return response()->json(['updated_at' => $booking->review_updated_at->toISOString()]);
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

        return back()->with('success', "Booking: {$booking->status_label}.");
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
