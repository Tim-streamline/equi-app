<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_guard_uses_separate_admin_user_model(): void
    {
        $this->assertSame(AdminUser::class, config('auth.providers.admin_users.model'));
        $this->assertSame('admin_users', config('auth.guards.admin.provider'));

        $admin = AdminUser::query()->create([
            'name' => 'Ops Admin',
            'email' => 'ops@example.com',
            'password' => 'secret-password',
            'role' => 'owner',
            'active' => true,
        ]);

        $this->assertDatabaseHas('admin_users', [
            'id' => $admin->id,
            'email' => 'ops@example.com',
            'role' => 'owner',
            'active' => true,
        ]);
        $this->assertNotSame('secret-password', $admin->password);
        $this->assertTrue(Hash::check('secret-password', $admin->password));

        Auth::guard('admin')->login($admin);

        $this->assertTrue(Auth::guard('admin')->check());
        $this->assertTrue(Auth::guard('admin')->user()->is($admin));
        $this->assertFalse(Auth::guard('web')->check());
    }
}
