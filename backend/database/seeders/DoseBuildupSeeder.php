<?php

namespace Database\Seeders;

use App\Models\DoseBuildup;
use Illuminate\Database\Seeder;

class DoseBuildupSeeder extends Seeder
{
    public function run(): void
    {
        DoseBuildup::firstOrCreate(['id' => DoseBuildup::NONE_ID], ['name' => 'No buildup', 'type' => 'linear', 'start_dose_ratio' => 1, 'days' => 1]);
        DoseBuildup::firstOrCreate(['id' => DoseBuildup::SEVEN_DAYS_ID], ['name' => '7 days', 'type' => 'linear', 'start_dose_ratio' => 0.25, 'days' => 7]);
        DoseBuildup::firstOrCreate(['id' => DoseBuildup::TWO_DAYS_HALF_ID], [
            'name' => 'Dag 1–2 50%, vanaf dag 3 100%', 'type' => 'custom', 'start_dose_ratio' => 0.5, 'days' => 3,
            'schedule' => [
                ['day' => 1, 'dose_ratio' => 0.5],
                ['day' => 2, 'dose_ratio' => 0.5],
                ['day' => 3, 'dose_ratio' => 1],
            ],
        ]);
    }
}
