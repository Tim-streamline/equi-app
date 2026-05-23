<?php

namespace Database\Seeders;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Horse;
use App\Models\NovaFallbackReply;
use App\Models\User;
use Illuminate\Database\Seeder;

class ChatSeeder extends Seeder
{
    public function run(): void
    {
        $replies = NovaFallbackReply::pluck('body')->all();

        foreach (User::all() as $user) {
            if (!fake()->boolean(70)) continue;

            $horse = Horse::where('owner_id', $user->id)->where('status', 'active')->first();
            $session = ChatSession::create([
                'user_id' => $user->id,
                'horse_id' => $horse?->id,
                'started_at' => fake()->dateTimeBetween('-2 months', 'now'),
            ]);

            $messages = fake()->numberBetween(2, 10);
            $order = 0;
            ChatMessage::create([
                'session_id' => $session->id,
                'role' => 'assistant',
                'body' => "Hi {$user->name}! Wat speelt er bij " . ($horse?->name ?? 'je paard') . '?',
                'order' => $order++,
                'created_at' => $session->started_at,
                'updated_at' => $session->started_at,
            ]);
            for ($i = 0; $i < $messages; $i++) {
                $at = (clone $session->started_at)->modify('+' . ($i + 1) * fake()->numberBetween(1, 5) . ' minutes');
                ChatMessage::create([
                    'session_id' => $session->id,
                    'role' => 'user',
                    'body' => fake()->sentence(fake()->numberBetween(6, 16)),
                    'order' => $order++,
                    'created_at' => $at, 'updated_at' => $at,
                ]);
                $at = (clone $at)->modify('+30 seconds');
                ChatMessage::create([
                    'session_id' => $session->id,
                    'role' => 'assistant',
                    'body' => fake()->randomElement($replies),
                    'order' => $order++,
                    'created_at' => $at, 'updated_at' => $at,
                ]);
            }
        }
    }
}
