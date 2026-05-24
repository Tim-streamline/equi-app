<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Horse;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class HorseController extends Controller
{
    public function index(Request $request): Response
    {
        $horses = Horse::query()
            ->when($request->string('q')->toString(), fn ($query, $q) => $query
                ->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('breed', 'ilike', "%{$q}%")->orWhere('stable', 'ilike', "%{$q}%")))
            ->when($request->string('status')->toString(), fn ($query, $s) => $query->where('status', $s))
            ->with('owner:id,name,email')
            ->withCount(['observations', 'scans', 'shares'])
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Horse $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'breed' => $h->breed,
                'sex' => $h->sex,
                'age' => $h->age,
                'stable' => $h->stable,
                'status' => $h->status,
                'owner' => $h->owner ? ['id' => $h->owner->id, 'name' => $h->owner->name] : null,
                'observations_count' => $h->observations_count,
                'scans_count' => $h->scans_count,
                'shares_count' => $h->shares_count,
            ]);

        return Inertia::render('Horses/Index', [
            'horses' => $horses,
            'filters' => $request->only('q', 'status'),
        ]);
    }

    public function show(Horse $horse): Response
    {
        $horse->load([
            'owner:id,name,email',
            'focusTopics:id,title,icon',
            'shares.granteeUser:id,name', 'shares.therapist:id,name',
            'stats',
            'activeProtocol',
            'protocols' => fn ($q) => $q->latest(),
            'observations' => fn ($q) => $q->with('author:id,name')->latest('date')->limit(15),
            'scans' => fn ($q) => $q->latest('scanned_at')->limit(10),
            'timeline' => fn ($q) => $q->latest()->limit(20),
            'intakeBookings.therapist:id,name',
        ]);

        return Inertia::render('Horses/Show', ['horse' => $horse]);
    }

    public function update(Request $request, Horse $horse): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'breed' => ['nullable', 'string', 'max:255'],
            'age' => ['nullable', 'integer', 'min:0', 'max:60'],
            'sex' => ['nullable', 'in:merrie,ruin,hengst'],
            'weight_kg' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'stable' => ['nullable', 'string', 'max:255'],
        ]);

        $before = $horse->only(array_keys($data));
        $horse->update($data);
        AuditLogger::updated($horse, $before);

        return back()->with('success', 'Horse updated.');
    }

    public function archive(Request $request, Horse $horse): RedirectResponse
    {
        $horse->update([
            'status' => 'archived',
            'archived_at' => Carbon::now(),
            'archived_note' => $request->input('note'),
        ]);
        AuditLogger::log('archive', $horse, reason: $request->input('note'));

        return back()->with('success', 'Horse archived.');
    }

    public function restore(Horse $horse): RedirectResponse
    {
        $horse->update(['status' => 'active', 'archived_at' => null, 'archived_note' => null]);
        AuditLogger::log('restore', $horse);

        return back()->with('success', 'Horse restored.');
    }

    public function transfer(Request $request, Horse $horse): RedirectResponse
    {
        $data = $request->validate(['owner_id' => ['required', 'exists:users,id']]);
        $before = ['owner_id' => $horse->owner_id];
        $horse->update(['owner_id' => $data['owner_id']]);
        AuditLogger::log('transfer', $horse, before: $before, after: ['owner_id' => $data['owner_id']]);

        return back()->with('success', 'Ownership transferred to '.User::find($data['owner_id'])?->name.'.');
    }
}
