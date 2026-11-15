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

    public function test_repeated_failed_admin_logins_are_throttled_per_account_and_network(): void
    {
        $this->admin('ops@example.com');
        for ($i = 0; $i < 10; $i++) {
            $this->attempt('ops@example.com', 'wrong')->assertRedirect()->assertSessionHasErrors('email');
        }
        $this->attempt('ops@example.com', 'wrong')->assertStatus(429);
        // The correct password is refused too while the account is locked from this network.
        $this->attempt('ops@example.com', 'secret-password')->assertStatus(429);
        // Another account from the same network and the same account from another network are unaffected.
        $this->admin('other@example.com');
        $this->attempt('other@example.com', 'wrong')->assertSessionHasErrors('email');
        $this->attempt('ops@example.com', 'secret-password', '198.51.100.9')->assertRedirect(route('admin.dashboard'));
    }

    public function test_admin_login_throttle_normalizes_email_case(): void
    {
        $this->admin('ops@example.com');
        foreach (range(1, 10) as $i) {
            $this->attempt($i % 2 ? 'OPS@Example.com' : 'ops@example.com', 'wrong')->assertSessionHasErrors('email');
        }
        $this->attempt('Ops@EXAMPLE.com', 'wrong')->assertStatus(429);
    }

    public function test_successful_admin_logins_do_not_consume_the_budget(): void
    {
        $this->admin('ops@example.com');
        for ($i = 0; $i < 15; $i++) {
            $this->attempt('ops@example.com', 'secret-password')->assertRedirect(route('admin.dashboard'));
        }
        for ($i = 0; $i < 10; $i++) {
            $this->attempt('ops@example.com', 'wrong')->assertSessionHasErrors('email');
        }
        $this->attempt('ops@example.com', 'wrong')->assertStatus(429);
    }

    public function test_failed_attempts_count_while_already_signed_in(): void
    {
        $admin = $this->admin('ops@example.com');
        $this->actingAs($admin, 'admin');
        for ($i = 0; $i < 10; $i++) {
            $this->attempt('ops@example.com', 'wrong')->assertSessionHasErrors('email');
        }
        $this->assertTrue(Auth::guard('admin')->check());
        $this->attempt('ops@example.com', 'wrong')->assertStatus(429);
    }

    public function test_one_network_cannot_try_many_admin_accounts(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->attempt("guess{$i}@example.com", 'wrong')->assertSessionHasErrors('email');
        }
        $this->attempt('another@example.com', 'wrong')->assertStatus(429);
        $this->attempt('another@example.com', 'wrong', '198.51.100.9')->assertSessionHasErrors('email');
    }

    private function admin(string $email): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'Ops Admin', 'email' => $email, 'password' => 'secret-password', 'role' => 'owner', 'active' => true,
        ]);
    }

    private function attempt(string $email, string $password, string $ip = '203.0.113.7')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/admin/login', ['email' => $email, 'password' => $password]);
    }
}
