<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Protocol;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProtocolController extends Controller
{
    public function index(Request $request): Response
    {
        $protocols = Protocol::query()
            ->when($request->string('status')->toString(), fn ($query, $s) => $query->where('status', $s))
            ->when($request->string('q')->toString(), fn ($query, $q) => $query->where('title', 'ilike', "%{$q}%"))
            ->with('horse:id,name,owner_id', 'horse.owner:id,name', 'therapist:id,name')
            ->withCount('tasks')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Protocols/Index', [
            'protocols' => $protocols,
            'filters' => $request->only('status', 'q'),
        ]);
    }

    public function show(Protocol $protocol): Response
    {
        $protocol->load([
            'horse:id,name,owner_id', 'horse.owner:id,name',
            'therapist:id,name,title',
            'phases.items',
            'analysis',
            'tasks' => fn ($q) => $q->withCount('completions'),
        ]);

        return Inertia::render('Protocols/Show', ['protocol' => $protocol]);
    }

    public function updateStatus(Request $request, Protocol $protocol): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', 'in:active,paused,completed']])['status'];
        $before = $protocol->only('status');
        $protocol->update(['status' => $status]);
        AuditLogger::updated($protocol, $before, $request->input('reason'));

        return back()->with('success', "Protocol marked {$status}.");
    }
}
