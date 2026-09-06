<?php

namespace Database\Seeders;

use App\Models\BewegingAdvies;
use Illuminate\Database\Seeder;

class BewegingAdviesSeeder extends Seeder
{
    /** @var array<int, array{title: string, description: string}> */
    private const ITEMS = [
        [
            'title' => 'Dagelijkse vrije beweging',
            'description' => 'Geef het paard minimaal 16 uur per dag, maar bij voorkeur 24/7, de ruimte om vrij te bewegen. Verspreid ruwvoer, water, schuil- en rustplekken over het terrein om natuurlijke beweging te stimuleren en zo de darmwerking, doorbloeding, stofwisseling en lichamelijke ontspanning te ondersteunen.',
        ],
        [
            'title' => 'Regelmatige arbeid',
            'description' => 'Zorg, wanneer de fysieke toestand dit toelaat, minimaal drie keer per week voor voldoende beweging om licht te zweten. Bouw de belasting geleidelijk op en wissel training af met rustige beweging en hersteldagen.',
        ],
        [
            'title' => 'Bewegen zonder trainingsdoel',
            'description' => 'Maak regelmatig samen een wandeling of laat het paard op eigen tempo bewegen, zonder prestatiedoel. Dit biedt beweging zonder extra mentale of lichamelijke druk.',
        ],
        [
            'title' => 'Extra aandacht bij bewegingsproblemen',
            'description' => 'Onderzoek pijn of lichamelijke beperkingen wanneer het paard weinig beweegt, struikelt, stijf loopt of arbeid vermijdt. Bij acute pijn of hoefbevangenheid mag beweging niet worden geforceerd en wordt arbeid pas hervat zodra het paard stabiel en comfortabel beweegt.',
        ],
        [
            'title' => 'Vrij spel en hersenwerk',
            'description' => 'Bied regelmatig speelse activiteiten aan, zoals obstakels, zeiltjes, ballen, een snuffelmat of eenvoudige denkspellen. Geef het paard de ruimte om zelf te onderzoeken en initiatief te nemen, zonder prestatiedruk of vast trainingsdoel.',
        ],
        [
            'title' => 'Longeren en volte beperken',
            'description' => 'Bij pees- of gewrichtsklachten geen korte volte, overleg met revalidatieplan.',
        ],
    ];

    public function run(): void
    {
        foreach (self::ITEMS as $item) {
            BewegingAdvies::query()->updateOrCreate(
                ['title' => $item['title']],
                [...$item, 'layout' => 'normal', 'icon' => null],
            );
        }
    }
}
