<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Models\NovaFallbackReply;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NovaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Nova/Index', [
            'replies' => NovaFallbackReply::orderBy('order')->get(),
            'sessionStats' => [
                'sessions' => ChatSession::count(),
                'recent' => ChatSession::with('user:id,name')->latest('started_at')->limit(10)
                    ->get(['id', 'user_id', 'horse_id', 'started_at']),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $reply = NovaFallbackReply::create($request->validate([
            'body' => ['required', 'string'],
            'order' => ['nullable', 'integer', 'min:0'],
        ]));
        AuditLogger::created($reply);

        return back()->with('success', 'Fallback reply added.');
    }

    public function update(Request $request, NovaFallbackReply $nova): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string'],
            'order' => ['nullable', 'integer', 'min:0'],
        ]);
        $before = $nova->only(array_keys($data));
        $nova->update($data);
        AuditLogger::updated($nova, $before);

        return back()->with('success', 'Fallback reply updated.');
    }

    public function destroy(NovaFallbackReply $nova): RedirectResponse
    {
        AuditLogger::deleted($nova);
        $nova->delete();

        return back()->with('success', 'Fallback reply removed.');
    }
}
