<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('plus_pages')->get() as $page) {
            $content = json_decode($page->content, true, flags: JSON_THROW_ON_ERROR);
            $content['heroTitle'] = 'Wat als je eindelijk stopt met gokken wat jouw paard nodig heeft?';
            $content['heroBody'] = "Al die supplementen, behandelingen en goedbedoelde adviezen. Maar wordt jouw paard er écht beter van?\nTijd voor een aanpak die wél ergens op gebaseerd is. Met EquiBoost krijg je een op maat gemaakt gezondheidsprotocol, zodat je gericht kunt werken aan een paard dat weer lekker in het vel zit.";
            DB::table('plus_pages')->where('id', $page->id)->update(['content' => json_encode($content, JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        // Keep authored presentation content when rolling back.
    }
};
