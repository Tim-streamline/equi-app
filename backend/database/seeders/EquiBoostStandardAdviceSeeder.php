<?php

namespace Database\Seeders;

use App\Support\EquiBoostStandardAdvice;
use Illuminate\Database\Seeder;

class EquiBoostStandardAdviceSeeder extends Seeder
{
    public function run(): void
    {
        app(EquiBoostStandardAdvice::class)->install();
    }
}
