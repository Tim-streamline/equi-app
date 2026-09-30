<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationBrandRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_migration_updates_existing_display_text_without_changing_identity_or_history(): void
    {
        $admin = AdminUser::create([
            'name' => 'EquiNova Owner', 'email' => 'owner@example.com',
            'password' => 'secret-password', 'role' => 'owner', 'active' => true,
        ]);
        $user = User::factory()->create(['name' => 'Shelley']);
        $horseId = (string) Str::uuid();
        DB::table('horses')->insert(['id' => $horseId, 'owner_id' => $user->id, 'name' => 'Nova']);
        $timelineId = (string) Str::uuid();
        DB::table('timeline_events')->insert([
            'id' => $timelineId, 'horse_id' => $horseId, 'occurred_at' => '2026-09-01 12:00:00',
            'kind' => 'horse_added', 'message' => 'Nova toegevoegd aan EquiNova',
        ]);
        $planId = (string) Str::uuid();
        DB::table('plans')->insert([
            'id' => $planId, 'slug' => 'plus', 'name' => 'EquiNova Plus',
            'description' => 'Equinova en EQUINOVA; Equi App blijft.', 'price_cents' => 1900, 'interval' => 'monthly',
        ]);
        DB::table('plus_pages')->insert(['id' => 'branding-test', 'content' => json_encode(['confirmationTitle' => 'EquiApp Plus'])]);
        $auditId = (string) Str::uuid();
        DB::table('audit_logs')->insert([
            'id' => $auditId, 'admin_user_id' => $admin->id, 'actor_name' => 'EquiNova Owner',
            'target_type' => 'AdminUser', 'target_id' => $admin->id, 'target_label' => 'Equinova Owner',
            'action' => 'login', 'created_at' => '2026-09-01 12:00:00',
        ]);

        $migration = require database_path('migrations/2026_09_30_180000_rename_application_brand_to_equi_app.php');
        $migration->up();
        $migration->up();

        $this->assertSame('Equi App Plus', json_decode(DB::table('plus_pages')->where('id', 'branding-test')->value('content'), true)['confirmationTitle']);
        $this->assertDatabaseHas('admin_users', ['id' => $admin->id, 'name' => 'Equi App Owner', 'email' => 'owner@example.com']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Shelley']);
        $this->assertDatabaseHas('horses', ['id' => $horseId, 'name' => 'Nova', 'owner_id' => $user->id]);
        $this->assertDatabaseHas('plans', [
            'id' => $planId, 'slug' => 'plus', 'name' => 'Equi App Plus',
            'description' => 'Equi App en Equi App; Equi App blijft.', 'price_cents' => 1900,
        ]);
        $this->assertDatabaseHas('timeline_events', [
            'id' => $timelineId, 'horse_id' => $horseId, 'message' => 'Nova toegevoegd aan Equi App',
            'occurred_at' => '2026-09-01 12:00:00',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'id' => $auditId, 'admin_user_id' => $admin->id, 'actor_name' => 'Equi App Owner',
            'target_type' => 'AdminUser', 'target_id' => $admin->id, 'target_label' => 'Equi App Owner',
            'action' => 'login', 'created_at' => '2026-09-01 12:00:00',
        ]);
    }
}
