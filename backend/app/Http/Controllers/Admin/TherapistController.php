<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Therapist;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TherapistController extends Controller
{
    public function index(): Response
    {
        $therapists = Therapist::query()
            ->withCount(['protocols' => fn ($q) => $q->where('status', 'active')])
            ->withCount('authoredLibraryItems')
            ->orderBy('name')
            ->get()
            ->map(fn (Therapist $t) => [
                ...$t->toArray(),
                'active_protocols' => $t->protocols_count,
                'library_items' => $t->authored_library_items_count,
                'bookings_pending' => $t->intakeBookings()->where('status', 'pending')->count(),
            ]);

        return Inertia::render('Therapists/Index', ['therapists' => $therapists]);
    }

    public function store(Request $request): RedirectResponse
    {
        $therapist = Therapist::create($this->validateData($request));
        AuditLogger::created($therapist);

        return back()->with('success', 'Therapist created.');
    }

    public function update(Request $request, Therapist $therapist): RedirectResponse
    {
        $data = $this->validateData($request);
        $before = $therapist->only(array_keys($data));
        $therapist->update($data);
        AuditLogger::updated($therapist, $before);

        return back()->with('success', 'Therapist updated.');
    }

    public function destroy(Therapist $therapist): RedirectResponse
    {
        AuditLogger::deleted($therapist);
        $therapist->delete();

        return back()->with('success', 'Therapist removed.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string'],
            'avatar_url' => ['nullable', 'url'],
            'avatar_initial' => ['nullable', 'string', 'max:4'],
            'avatar_color' => ['nullable', 'string', 'max:16'],
            'verified' => ['boolean'],
        ]);
    }
}
