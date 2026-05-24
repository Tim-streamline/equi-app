<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(): Response
    {
        $plans = Plan::with('benefits:id,plan_id,label,order')
            ->withCount(['subscriptions' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('order')
            ->get();

        return Inertia::render('Plans/Index', ['plans' => $plans]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['slug'] ??= Str::slug($data['name']);
        $plan = Plan::create($data);
        $this->syncBenefits($plan, $request);
        AuditLogger::created($plan);

        return back()->with('success', 'Plan created.');
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $this->validateData($request, $plan->id);
        $before = $plan->only(array_keys($data));
        $plan->update($data);
        $this->syncBenefits($plan, $request);
        AuditLogger::updated($plan, $before);

        return back()->with('success', 'Plan updated.');
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        if ($plan->subscriptions()->exists()) {
            return back()->with('error', 'Cannot delete a plan with subscriptions attached.');
        }
        AuditLogger::deleted($plan);
        $plan->delete();

        return back()->with('success', 'Plan removed.');
    }

    private function syncBenefits(Plan $plan, Request $request): void
    {
        if (! $request->has('benefits')) {
            return;
        }
        $plan->benefits()->delete();
        foreach ($request->input('benefits', []) as $i => $label) {
            if (filled($label)) {
                $plan->benefits()->create(['label' => $label, 'order' => $i]);
            }
        }
    }

    private function validateData(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:plans,slug'.($id ? ",{$id}" : '')],
            'price_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'max:8'],
            'interval' => ['required', 'in:monthly,one_time'],
            'price_suffix' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_recommended' => ['boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
