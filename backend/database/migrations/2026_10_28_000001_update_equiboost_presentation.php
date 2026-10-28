<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('plus_pages')->get() as $page) {
            $content = json_decode($page->content, true, flags: JSON_THROW_ON_ERROR);
            $content = array_replace($content, [
                'pageTitle' => 'Begeleiding op maat',
                'heroLabel' => 'EQUIBOOST | 3 MAANDEN GERICHT AAN DE SLAG',
                'period' => 'Persoonlijk traject van 3 maanden',
                'pricePeriod' => 'Traject op maat gedurende 3 maanden',
                'heroButton' => 'Start mijn EquiBoost traject',
                'priceButton' => 'Start mijn EquiBoost traject',
            ]);
            DB::table('plus_pages')->where('id', $page->id)->update(['content' => json_encode($content, JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        // Keep authored presentation content when rolling back.
    }
};
