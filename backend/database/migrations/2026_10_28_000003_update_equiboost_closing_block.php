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
                'closingLabel' => 'KLAAR OM TE BEGINNEN?',
                'closingTitle' => 'Je hebt al genoeg tijd besteed aan zoeken. Tijd om stappen te zetten.',
                'closingBody' => 'Gun jouw paard een aanpak die écht past. Met drie maanden EquiBoost werk je stap voor stap aan een betere gezondheid, met een plan dat meebeweegt met de voortgang van jouw paard.',
                'closingButton' => 'Ja, ik wil starten met EquiBoost!',
            ]);
            DB::table('plus_pages')->where('id', $page->id)->update(['content' => json_encode($content, JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        // Keep authored presentation content when rolling back.
    }
};
