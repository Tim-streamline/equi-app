<?php

namespace Database\Seeders;

use App\Models\ManagementAdvies;
use Illuminate\Database\Seeder;

class ManagementAdviesSeeder extends Seeder
{
    /** @var array<int, array{title: string, description: string}> */
    private const ITEMS = [
        [
            'title' => 'Weidebeleid',
            'description' => 'Kort, jong gras bevat relatief veel pectine en fructaan en kan de darmflora verstoren. Laat het gras daarom niet korter dan 6 cm afgrazen en bouw de weidegang in het voorjaar geleidelijk op en in het najaar rustig weer af.',
        ],
        [
            'title' => 'Geen weidegang tijdens protocol',
            'description' => 'Laat het paard tijdens het protocol niet op de weide. Zo voorkom je dat wisselende hoeveelheden suiker, fructaan en pectine uit gras de darmflora en stofwisseling blijven belasten en het herstel verstoren.',
        ],
        [
            'title' => 'Voerplekken',
            'description' => 'Creëer 2x zoveel eetplaatsen als er paarden zijn. Verdeel het ruwvoer over meerdere, van elkaar gescheiden voerstations en maak binnen ieder station meerdere eetplaatsen. Zo kunnen de paarden in elkaars nabijheid eten, terwijl ze voldoende ruimte hebben om uit te wijken en voerconcurrentie afneemt.',
        ],
        [
            'title' => 'Hoefverzorging',
            'description' => 'Laat de hoeven iedere 3-4 weken goed bekappen. Extra aandacht is nodig bij peesproblemen, struikelen, brokkelige hoeven, gevoelig lopen, staakgedrag of hoefbevangenheid.',
        ],
        [
            'title' => 'Gebitscontrole',
            'description' => 'Laat het gebit minimaal één keer per jaar controleren, zodat scherpe randen, haken en ongelijkmatige slijtage tijdig worden ontdekt. Laat eerder controleren bij proppen maken, langzaam of eenzijdig kauwen, voer laten vallen, vermageren, slechte adem of onverteerde vezels in de mest.',
        ],
        [
            'title' => 'Fysieke behandelingen',
            'description' => 'Reeks fysieke behandelingen inplannen met ca. 4 weken interval. Ongemak veroorzaakt meer spanning en compensatie, waardoor het lichaam opnieuw wordt overbelast. Door ook het lichamelijke deel aan te pakken, help je deze vicieuze cirkel te doorbreken.',
        ],
        [
            'title' => 'Mestonderzoek',
            'description' => 'Laat periodiek mestonderzoek uitvoeren, zeker bij aanhoudende veranderingen in mestconsistentie, mestwater, gewichtsverlies, een doffe vacht, terugkerende buikklachten of zand eten. Houd er rekening mee dat een mestonderzoek niet alle parasieten of maag-darmproblemen uitsluit. Bij blijvende klachten is herhaald mestonderzoek of aanvullend onderzoek via de dierenarts nodig.',
        ],
        [
            'title' => 'Bloedonderzoek bij vermoeden IR',
            'description' => 'Vraag de DA om een volledig EMS-profiel met minimaal glucose en insuline. Voor een basale meting hoeft het paard niet nuchter te zijn: gewoon hooi voeren, maar gedurende 12u geen gras, krachtvoer, snacks of ander suikerrijk voer. Laat glucose bepalen uit een natriumfluoridebuis en insuline uit een apart serum- of plasmamonster dat volgens de instructies van het laboratorium snel wordt verwerkt; referentiewaarden verschillen per lab en analysemethode. Test bij voorkeur buiten een acute pijn-, ziekte- of stresssituatie en bespreek bij een normale basale uitslag maar blijvende verdenking een dynamische test, omdat één normale rustwaarde insulinedysregulatie niet uitsluit.',
        ],
        [
            'title' => 'Urineonderzoek bij vermoeden KPU',
            'description' => 'Bij aanhoudende / terugkerende klachten die onvoldoende verbeteren ondanks passend ruwvoer, management en een gerichte aanpak van de darmgezondheid, zoals mestveranderingen, huidklachten, overgevoeligheid, spierproblemen of verminderde belastbaarheid.',
        ],
    ];

    public function run(): void
    {
        foreach (self::ITEMS as $item) {
            ManagementAdvies::query()->updateOrCreate(
                ['title' => $item['title']],
                [...$item, 'layout' => 'normal', 'icon' => null],
            );
        }
    }
}
