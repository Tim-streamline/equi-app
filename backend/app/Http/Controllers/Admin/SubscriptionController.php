<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function index(Request $request): Response
    {
        $subscriptions = Subscription::query()
            ->when($request->string('status')->toString(), fn ($query, $s) => $query->where('status', $s))
            ->when($request->string('plan')->toString(), fn ($query, $p) => $query->where('plan_id', $p))
            ->with('user:id,name,email', 'plan:id,name')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Subscriptions/Index', [
            'subscriptions' => $subscriptions,
            'filters' => $request->only('status', 'plan'),
            'plans' => Plan::orderBy('order')->get(['id', 'name']),
            'mrr_cents' => (int) Subscription::where('status', 'active')->where('interval', 'monthly')->sum('price_cents'),
        ]);
    }

    public function show(Subscription $subscription): Response
    {
        $subscription->load('user:id,name,email', 'plan', 'payments');

        return Inertia::render('Subscriptions/Show', ['subscription' => $subscription]);
    }

    public function update(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,cancelled,past_due'],
            'plan_id' => ['required', 'exists:plans,id'],
            'renews_at' => ['nullable', 'date'],
            'max_horses' => ['required', 'integer', 'min:1', 'max:50'],
        ]);
        $before = $subscription->only(array_keys($data));
        $subscription->update($data);
        AuditLogger::updated($subscription, $before, $request->input('reason'));

        return back()->with('success', 'Subscription updated.');
    }
}
