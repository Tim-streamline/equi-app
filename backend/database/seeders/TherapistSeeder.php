<?php

namespace Database\Seeders;

use App\Models\Therapist;
use Illuminate\Database\Seeder;

class TherapistSeeder extends Seeder
{
    public function run(): void
    {
        Therapist::create([
            'name' => 'Shelley',
            'title' => 'De Paardentherapeut',
            'bio' => 'Holistische paardentherapeut met 15+ jaar ervaring. Specialiseert in voeding, darmgezondheid en gedrag.',
            'avatar_initial' => 'S',
            'avatar_color' => '#0D5C5B',
            'verified' => true,
        ]);

        $others = [
            ['Marlies van der Heuvel', 'Holistisch dierenarts', 'M', '#127A79'],
            ['Pieter de Boer', 'Hoefspecialist', 'P', '#2EA875'],
            ['Anneke Smit', 'Kruidengeneeskunde', 'A', '#D9A441'],
        ];
        foreach ($others as [$name, $title, $initial, $color]) {
            Therapist::create([
                'name' => $name,
                'title' => $title,
                'bio' => fake()->paragraphs(2, true),
                'avatar_initial' => $initial,
                'avatar_color' => $color,
                'verified' => true,
            ]);
        }
    }
}
