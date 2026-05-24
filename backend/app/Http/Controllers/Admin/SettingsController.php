<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'admins' => AdminUser::orderBy('name')->get(['id', 'name', 'email', 'role', 'active', 'created_at']),
            'roles' => collect(AdminUser::ROLES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
        ]);
    }

    public function storeAdmin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:admin_users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(array_keys(AdminUser::ROLES))],
        ]);
        $data['password'] = Hash::make($data['password']);
        $admin = AdminUser::create($data);
        AuditLogger::created($admin);

        return back()->with('success', 'Admin user created.');
    }

    public function updateAdmin(Request $request, AdminUser $admin): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:admin_users,email,'.$admin->id],
            'role' => ['required', Rule::in(array_keys(AdminUser::ROLES))],
            'active' => ['boolean'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);
        if (filled($data['password'] ?? null)) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        $before = $admin->only(['name', 'email', 'role', 'active']);
        $admin->update($data);
        AuditLogger::updated($admin, $before);

        return back()->with('success', 'Admin user updated.');
    }

    public function destroyAdmin(Request $request, AdminUser $admin): RedirectResponse
    {
        if ($admin->id === $request->user('admin')->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }
        AuditLogger::deleted($admin);
        $admin->delete();

        return back()->with('success', 'Admin user removed.');
    }
}
