<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Models\NovaFallbackReply;
use App\Support\AuditLogger;
use App\Support\Shelby\ShelbyAssistant;
use App\Support\Shelby\ShelbyModel;
use Illuminate\Http\JsonResponse;
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
            'shelby' => [
                'live' => config('shelby.provider'),
                'providers' => collect(ShelbyModel::PROVIDERS)->map(fn ($p) => ['id' => $p, 'model' => app(ShelbyModel::class)->model($p),
                    'configured' => app(ShelbyModel::class)->configured($p)])->all(),
            ],
        ]);
    }

    /** Ask the same question to every configured AI so the answers can be compared side by side. */
    public function test(Request $request, ShelbyAssistant $shelby): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'history' => ['sometimes', 'array', 'max:16'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.body' => ['required', 'string', 'max:4000'],
            'has_plus' => ['sometimes', 'boolean'],
        ]);
        $results = [];
        foreach (ShelbyModel::PROVIDERS as $provider) {
            if (! app(ShelbyModel::class)->configured($provider)) {
                $results[] = ['provider' => $provider, 'error' => 'Geen API-sleutel ingesteld.'];
                continue;
            }
            try {
                $results[] = $shelby->answer($data['question'], $data['history'] ?? [], null, (bool) ($data['has_plus'] ?? false), $provider);
            } catch (\Throwable) {
                $results[] = ['provider' => $provider, 'model' => app(ShelbyModel::class)->model($provider), 'error' => 'Geen antwoord ontvangen. Controleer de sleutel en het model.'];
            }
        }

        return response()->json(['results' => $results]);
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
