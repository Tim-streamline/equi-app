<?php

namespace Database\Seeders;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Database\Seeder;

class IntakeBookingSeeder extends Seeder
{
    public function run(): void
    {
        $shelley = Therapist::where('name', 'Shelley')->first();
        $therapistIds = Therapist::pluck('id')->all();
        $months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
        $days = ['Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag', 'Zondag'];

        // Marit's pending intake (matches the frontend mock).
        $marit = User::where('email', 'marit@voorbeeld.nl')->first();
        $nova = Horse::where('owner_id', $marit->id)->where('name', 'Nova')->first();
        IntakeBooking::create([
            'user_id' => $marit->id,
            'horse_id' => $nova?->id,
            'therapist_id' => $shelley->id,
            'scheduled_at' => now()->addDays(7)->setTime(14, 30),
            'slot_label' => 'Vrijdag 18 mei · 14:30',
            'duration_minutes' => 30,
            'status' => 'pending',
        ]);

        foreach (User::where('id', '!=', $marit->id)->inRandomOrder()->limit(15)->get() as $user) {
            $horse = Horse::where('owner_id', $user->id)->where('status', 'active')->first();
            $when = fake()->dateTimeBetween('-2 months', '+3 weeks');
            $weekday = (int) $when->format('N') - 1;
            IntakeBooking::create([
                'user_id' => $user->id,
                'horse_id' => $horse?->id,
                'therapist_id' => fake()->randomElement($therapistIds),
                'scheduled_at' => $when,
                'slot_label' => $days[$weekday] . ' ' . $when->format('j') . ' ' . $months[(int) $when->format('n') - 1] . ' · ' . $when->format('H:i'),
                'duration_minutes' => fake()->randomElement([30, 45, 60]),
                'status' => $when < new \DateTime() ? fake()->randomElement(['done', 'cancelled', 'done']) : fake()->randomElement(['pending', 'confirmed', 'pending']),
                'notes' => fake()->boolean(30) ? fake()->sentence() : null,
            ]);
        }
    }
}
