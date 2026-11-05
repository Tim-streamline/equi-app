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
        $this->assertDatabaseCount('plans', 1);
        $this->assertDatabaseHas('plans', ['slug' => 'subscription']);

        $anchorUser = User::where('email', UserSeeder::ANCHOR_EMAIL)->firstOrFail();

        $this->assertDatabaseHas('horses', [
            'owner_id' => $anchorUser->id,
            'name' => 'Nova',
        ]);
        // Random users may also own a horse named Nova; an unordered global
        // first() is not necessarily the anchor user's hand-crafted horse.
        $this->assertSame(1, Horse::where('owner_id', $anchorUser->id)->where('name', 'Nova')->count());
    }
}
