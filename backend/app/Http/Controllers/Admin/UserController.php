<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DataExport;
use App\Models\User;
use App\Models\UserRestriction;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()
            ->when($request->string('q')->toString(), function ($query, $q) {
                $query->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%"));
            })
            ->when($request->string('status')->toString(), function ($query, $status) {
                match ($status) {
                    'onboarded' => $query->whereNotNull('onboarded_at'),
                    'pending' => $query->whereNull('onboarded_at'),
                    'disabled' => $query->whereNotNull('disabled_at'),
                    default => null,
                };
            })
            ->withCount(['horses', 'scans', 'communityPosts'])
            ->with('subscription.plan:id,name')
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar_initial' => $u->avatar_initial,
                'plan' => $u->subscription?->plan?->name,
                'onboarded' => (bool) $u->onboarded_at,
                'disabled' => (bool) $u->disabled_at,
                'horses_count' => $u->horses_count,
                'scans_count' => $u->scans_count,
                'posts_count' => $u->community_posts_count,
                'created_at' => $u->created_at,
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => $request->only('q', 'status'),
        ]);
    }

    public function show(User $user): Response
    {
        $user->load([
            'horses' => fn ($q) => $q->withCount('observations', 'scans'),
            'subscription.plan',
            'subscriptions.plan',
            'notificationPreferences',
            'dataExports' => fn ($q) => $q->latest('requested_at')->limit(10),
            'restrictions' => fn ($q) => $q->latest(),
            'intakeBookings.therapist:id,name',
        ]);
        $user->loadCount(['scans', 'communityPosts', 'communityReplies', 'chatSessions']);

        return Inertia::render('Users/Show', [
            'user' => $user,
            'activeRestriction' => $user->restrictions
                ->whereNull('lifted_at')
                ->first(fn ($r) => ! $r->expires_at || $r->expires_at->isFuture()),
        ]);
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Users/Edit', ['user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email,'.$user->id],
            'locale' => ['nullable', 'string', 'max:16'],
            'units_system' => ['nullable', 'in:metric,imperial'],
            'notifications_on' => ['boolean'],
            'admin_note' => ['nullable', 'string', 'max:255'],
        ]);

        $before = $user->only(array_keys($data));
        $user->update($data);
        AuditLogger::updated($user, $before);

        return back()->with('success', 'User profile updated.');
    }

    public function disable(Request $request, User $user): RedirectResponse
    {
        $user->update(['disabled_at' => Carbon::now()]);
        AuditLogger::log('disable', $user, reason: $request->input('reason'));

        return back()->with('success', 'Account disabled.');
    }

    public function restore(User $user): RedirectResponse
    {
        $user->update(['disabled_at' => null]);
        AuditLogger::log('restore', $user);

        return back()->with('success', 'Account restored.');
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $temp = Str::password(16);
        $user->update(['password' => Hash::make($temp)]);
        AuditLogger::log('reset_password', $user);

        // In production this would dispatch a reset email; for the console we
        // surface the temporary credential once to the operator.
        return back()->with('success', "Password reset. Temporary password: {$temp}");
    }

    public function restrict(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:warning,mute,ban'],
            'reason' => ['nullable', 'string', 'max:500'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $restriction = UserRestriction::create([
            'user_id' => $user->id,
            'type' => $data['type'],
            'reason' => $data['reason'] ?? null,
            'expires_at' => isset($data['days']) ? Carbon::now()->addDays($data['days']) : null,
            'created_by' => $request->user('admin')->id,
        ]);
        AuditLogger::log('restrict', $user, after: $restriction->only('type', 'expires_at'), reason: $data['reason'] ?? null);

        return back()->with('success', ucfirst($data['type']).' applied.');
    }

    public function requestExport(Request $request, User $user): RedirectResponse
    {
        $format = $request->validate(['format' => ['required', 'in:csv,pdf']])['format'];

        $export = DataExport::create([
            'user_id' => $user->id,
            'format' => $format,
            'requested_at' => Carbon::now(),
        ]);
        AuditLogger::log('data_export_requested', $user, after: ['format' => $format, 'export_id' => $export->id]);

        return back()->with('success', "Queued a {$format} export for this user.");
    }
}
