<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\Protocol;
use App\Models\Therapist;
use App\Models\User;
use Database\Seeders\ProtocolSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtocolSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_idempotently_seeds_novas_protocol_for_the_anchor_user(): void
    {
        $anchorUser = User::query()->create([
            'name' => 'Shelley Meeuwsen',
            'email' => UserSeeder::ANCHOR_EMAIL,
            'password' => 'password',
        ]);
        $horse = Horse::query()->create([
            'owner_id' => $anchorUser->id,
            'name' => 'Nova',
            'breed' => 'Friese kruising',
            'age' => 9,
            'sex' => 'merrie',
            'status' => 'active',
        ]);
        Therapist::query()->create([
            'name' => 'Shelley',
            'title' => 'De Paardentherapeut',
            'verified' => true,
        ]);

        $this->seed(ProtocolSeeder::class);
        $this->seed(ProtocolSeeder::class);

        $protocol = Protocol::query()
            ->where('horse_id', $horse->id)
            ->where('title', "Nova's plan")
            ->with(['phases.items', 'analysis.advice', 'tasks.completions'])
            ->sole();

        $this->assertTrue($protocol->horse->owner->is($anchorUser));
        $this->assertSame('active', $protocol->status);
        $this->assertCount(3, $protocol->phases);
        $this->assertCount(4, $protocol->phases->firstWhere('state', 'active')->items);
        $this->assertCount(3, $protocol->analysis->advice);
        $this->assertCount(4, $protocol->tasks);
        $this->assertSame(56, $protocol->tasks->sum(fn ($task) => $task->completions->count()));
        $this->assertDatabaseCount('protocols', 1);
        $this->assertDatabaseCount('protocol_phases', 3);
        $this->assertDatabaseCount('protocol_tasks', 4);
        $this->assertDatabaseCount('protocol_task_completions', 56);
    }
}
