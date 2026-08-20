<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HorseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_assigns_nova_to_the_anchor_user(): void
    {
        $this->seed();

        $anchorUser = User::where('email', UserSeeder::ANCHOR_EMAIL)->firstOrFail();

        $this->assertDatabaseHas('horses', [
            'owner_id' => $anchorUser->id,
            'name' => 'Nova',
        ]);
        $this->assertSame(
            $anchorUser->id,
            Horse::where('name', 'Nova')->firstOrFail()->owner_id,
        );
    }
}
