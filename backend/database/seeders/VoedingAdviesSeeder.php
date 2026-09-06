<?php

namespace Database\Seeders;

use App\Models\VoedingAdvies;
use Illuminate\Database\Seeder;

class VoedingAdviesSeeder extends Seeder
{
    /** @var array<int, array{title: string, description: string}> */
    private const ITEMS = [
        [
            'title' => 'Ruwvoer als basis',
            'description' => 'Laat het rantsoen hoofdzakelijk bestaan uit onverpakt hooi (uit touwtjes). Geef 2 tot 3 kg hooi per 100 kg (gewenst) lichaamsgewicht en periodes zonder ruwvoer >2u (inclusief de nacht).',
        ],
        [
            'title' => 'Graszaadhooi beperken',
            'description' => 'Laat graszaadhooi maximaal een derde van het totale ruwvoerrantsoen vormen, omdat het eenzijdiger is dan passend weidehooi. Ga er niet automatisch van uit dat graszaadhooi suikerarm is: laat ook dit analyseren en beoordeel het binnen het volledige rantsoen.',
        ],
        [
            'title' => 'Stoppen met voordroog',
            'description' => 'Vervang voordroog zo snel mogelijk door onverpakt schimmelvrij hooi. Voordroog wordt geconserveerd door fermentatie en bevat melkzuurbacteriën en melkzuren die de zuurgraad en bacteriële balans in de darmen kunnen verstoren. Droger voordroog kan daarnaast ongemerkt meer schimmels, gisten en ongewenste bacteriën bevatten.',
        ],
        [
            'title' => 'Waterkwaliteit controleren',
            'description' => 'Laat de waterkwaliteit regelmatig onderzoeken wanneer het paard regenwater, slootwater of grondwater drinkt. Dit water kan bacteriën, algen, zware metalen, landbouwstoffen of een ongunstige mineralensamenstelling bevatten, ook wanneer het helder oogt en normaal ruikt. Bekijk voor meer uitleg de gratis video over waterkwaliteit in de bibliotheek.',
        ],
        [
            'title' => 'Zuivere balancer geven',
            'description' => 'Vul het ruwvoer dagelijks aan met een meukvrije balancer met anorganische mineralen. Geef de dosering die past bij het lichaamsgewicht en het gekozen product. Bekijk voor meer uitleg de video "wat dan wel" in de bibliotheek.',
        ],
        [
            'title' => 'Geef enkel zuivere bijvoeding',
            'description' => 'Gebruik alleen zoveel als nodig is om het protocol te laten eten, maximaal 60 tot 80 gram per 100 kg lichaamsgewicht per dag. Week het tot een slobber en bekijk de video \'Wat geef je dan wél?\' voor geschikte opties. Nieuwe voeding opbouwen gedurende 7-10 dagen.',
        ],
        [
            'title' => 'Stoppen met huidige bijvoeding',
            'description' => 'Afbouwen gedurende 7-10 dagen.',
        ],
        [
            'title' => 'Bij gewichtsverlies of gebitsproblemen',
            'description' => 'Kan je paard onvoldoende hooi eten of valt het af, dan kan geweekte, vezelrijke bijvoeding in een grotere hoeveelheid nodig zijn als aanvulling op of vervanging van ruwvoer. Bekijk hiervoor de video "Wat voer je bij gewichtsverlies of gebitsproblemen?"',
        ],
        [
            'title' => 'Geen fruit, wortels of snoepjes',
            'description' => 'Vervang door rozenbottel, hennep bite of komkommer',
        ],
        [
            'title' => 'Vers groen ter vrije selectie',
            'description' => 'Wilgentakken, kleefkruid, weegbree, paardenbloem, brandnetel',
        ],
        [
            'title' => 'Slowfeeders met kleine mazen',
            'description' => '3x3 cm, verlengt de eettijd · geleidelijk opbouwen als paard het niet gewend is, stress te allen tijde voorkomen',
        ],
        [
            'title' => 'Voerwissels geleidelijk doorvoeren',
            'description' => 'Oud en nieuw voer mengen over 7 tot 10 dagen',
        ],
        [
            'title' => 'Geen gehakseld ruwvoer (fibermix)',
            'description' => 'Te kort om te kauwen, verstoort de dikke darm',
        ],
        [
            'title' => 'Zoutsteen ter vrije beschikking',
            'description' => 'Bied een zoutsteen zonder melasse en organisch selenium aan, bijvoorbeeld een Himalaya-zoutsteen of PN-steen. Controleer altijd de samenstelling: ook andere zoutstenen kunnen geschikt zijn. De steen is bedoeld om af en toe aan te likken en hoort niet in korte tijd volledig te worden opgegeten.',
        ],
    ];

    public function run(): void
    {
        foreach (self::ITEMS as $item) {
            VoedingAdvies::query()->updateOrCreate(
                ['title' => $item['title']],
                [...$item, 'layout' => 'normal', 'icon' => null],
            );
        }
    }
}
