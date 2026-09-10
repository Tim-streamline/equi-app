<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Therapist;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class TherapistController extends Controller
{
    public function index(Request $request): Response
    {
        $archived = $request->boolean('archived');
        $therapists = Therapist::query()
            ->when($archived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->active())
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

        return Inertia::render('Therapists/Index', ['therapists' => $therapists, 'archived' => $archived,
            'counts' => ['active' => Therapist::active()->count(), 'archived' => Therapist::whereNotNull('archived_at')->count()]]);
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

    // Keep legacy DELETE clients safe: this route now archives, never deletes.
    public function destroy(Therapist $therapist): RedirectResponse
    {
        return $this->archive($therapist);
    }

    public function archive(Therapist $therapist): RedirectResponse
    {
        return $this->setArchived($therapist, true);
    }

    public function restore(Therapist $therapist): RedirectResponse
    {
        return $this->setArchived($therapist, false);
    }

    private function setArchived(Therapist $therapist, bool $archive): RedirectResponse
    {
        try {
            DB::transaction(function () use ($therapist, $archive) {
                $record = Therapist::whereKey($therapist->id)->lockForUpdate()->firstOrFail();
                if (($record->archived_at !== null) === $archive) {
                    return;
                }
                $before = $record->only('archived_at');
                $record->forceFill(['archived_at' => $archive ? now() : null])->save();
                AuditLogger::log($archive ? 'archived' : 'restored', $record,
                    before: $before, after: $record->only('archived_at'));
            });
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['archive' => ($archive ? 'Archiveren' : 'Herstellen').
                ' is niet gelukt. Er zijn geen wijzigingen bewaard. Probeer het opnieuw.']);
        }

        return back()->with('success', $archive ? 'Therapist / author gearchiveerd. Bestaande koppelingen zijn behouden.' : 'Therapist / author hersteld.');
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
