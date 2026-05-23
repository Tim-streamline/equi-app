<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            ReferenceSeeder::class,         // focus topics, plans, categories, tags, ingredients, replies
            TherapistSeeder::class,         // Shelley + 3 others
            ProductSeeder::class,           // ~20 products
            LibrarySeeder::class,           // ~50 items + chapters/sections, seasonal tips
            UserSeeder::class,              // 25 users + subs/payments/settings
            HorseSeeder::class,             // horses + protocols + observations + timeline + shares
            ScanSeeder::class,              // scans per user + ingredient breakdown
            CommunitySeeder::class,         // posts + replies + reactions
            LibraryEngagementSeeder::class, // bookmarks + progress
            ChatSeeder::class,              // nova chat history
            IntakeBookingSeeder::class,     // intake calendar
        ]);
    }
}
