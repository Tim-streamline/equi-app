<?php

namespace Tests\Feature;

use App\Mail\RegistrationConfirmation;
use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrandConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_name_is_consistent_in_shared_brand_config_and_rendered_mail(): void
    {
        $this->assertSame('EquiApp', config('app.brand_name'));
        foreach (['Equi App', 'Equi-App', 'Equiapp', 'EQUI·APP', 'EquiNova'] as $name) {
            $this->assertSame('EquiApp Staging', Brand::normalize($name.' Staging'));
            $this->assertSame('Welkom bij EquiApp.', Brand::normalize('Welkom bij '.$name.'.'));
        }
        $url = 'https://equi-app.online/registration/confirm/test';
        $mail = new RegistrationConfirmation('Shelley', $url);
        $this->assertSame('Bevestig je e-mailadres | EquiApp', $mail->envelope()->subject);
        $this->assertStringContainsString('aanmelding bij EquiApp', $mail->render());
        $this->assertStringContainsString('Het EquiApp-team', $mail->render());
        $this->assertStringContainsString($url, $mail->render());
        $this->assertStringNotContainsString('Equi App', $mail->render());
        $this->get('/admin/login')->assertOk()->assertSee('EquiApp Admin');
    }

    public function test_display_migration_is_idempotent_and_preserves_urls_json_keys_slugs_and_history(): void
    {
        $id = (string) Str::uuid();
        DB::table('plans')->insert([
            'id' => $id, 'slug' => 'legacy-equi-app', 'name' => 'Equi App Plus',
            'description' => 'Equi-App via https://equi-app.online; mail help@equi-app.online.',
            'price_cents' => 1900, 'interval' => 'monthly', 'created_at' => '2026-09-01 12:00:00',
        ]);
        DB::table('plus_pages')->insert(['id' => 'canonical-brand-test', 'content' => json_encode([
            'Equi App' => ['title' => 'Equiapp Plus', 'url' => 'https://equi-app.online/plus'],
        ])]);
        $migration = require database_path('migrations/2026_10_02_120000_standardize_equiapp_display_brand.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('plans', [
            'id' => $id, 'slug' => 'legacy-equi-app', 'name' => 'EquiApp Plus',
            'description' => 'EquiApp via https://equi-app.online; mail help@equi-app.online.',
            'price_cents' => 1900, 'created_at' => '2026-09-01 12:00:00',
        ]);
        $this->assertSame(['Equi App' => ['title' => 'EquiApp Plus', 'url' => 'https://equi-app.online/plus']],
            json_decode(DB::table('plus_pages')->where('id', 'canonical-brand-test')->value('content'), true));
    }
}
