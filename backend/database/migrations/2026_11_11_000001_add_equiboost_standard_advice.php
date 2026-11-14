<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Additional library advice; existing authored content and protocol snapshots stay intact. */
return new class extends Migration
{
    private const ITEMS = [
        'management_adviezen' => [
            ['title' => 'Wateropname controleren', 'description' => 'Houd de wateropname en het drinkgedrag van je paard in de gaten. Let op veranderingen in hoeveel en hoe vaak je paard drinkt. Onderzoek bij opvallend weinig of juist veel drinken wat hiervan de oorzaak kan zijn.'],
            ['title' => 'Wateropname stimuleren', 'description' => 'Stimuleer je paard om voldoende te drinken. Experimenteer indien nodig met de locatie, het type drinkbak en de watertemperatuur.'],
            ['title' => 'Automatische drinkbak controleren en alternatieve drinkmogelijkheid aanbieden', 'description' => 'Bij drinkbakken met een lepel of drukmechanisme kan het voorkomen dat deze te zwaar of moeilijk in te drukken is, waardoor paarden ongemerkt te weinig drinken. Controleer daarom of je paard de drinkbak gemakkelijk kan bedienen en of het water snel genoeg stroomt.

Bied bij voorkeur ook een alternatieve drinkmogelijkheid aan, zoals een ruime waterbak, trog of emmer.'],
            ['title' => 'Voldoende comfortabele ligplekken aanbieden', 'description' => 'Zorg voor voldoende droge, schone en comfortabele ligplekken met een geschikte ondergrond. Je paard moet veilig kunnen gaan liggen en opstaan. Voldoende gelegenheid om liggend te slapen is essentieel voor lichamelijk en mentaal herstel.'],
            ['title' => 'Ongestoorde rust en slaap mogelijk maken', 'description' => 'Observeer of je paard daadwerkelijk de gelegenheid heeft om ongestoord te rusten en ook PLAT te slapen. Let op verstoringen door andere paarden, onrust in de omgeving of een gebrek aan geschikte rustplekken. Pas de omstandigheden waar nodig aan.'],
            ['title' => 'Voldoende bescherming tegen weersinvloeden bieden', 'description' => 'Zorg dat je paard zich te allen tijde kan beschermen tegen regen, wind, felle zon en extreme temperaturen. Bied geschikte beschutting aan en houd rekening met de individuele behoeften van je paard en de weersomstandigheden.'],
            ['title' => 'Schuilplekken veilig en toegankelijk maken', 'description' => 'Controleer of ieder paard daadwerkelijk gebruik kan maken van de aanwezige beschutting. Let op voldoende ruimte, veilige en ruime doorgangen, ventilatie en een droge ondergrond. Voorkom dat andere paarden de toegang kunnen blokkeren.'],
            ['title' => 'Voldoende ruimte en uitwijkmogelijkheden creëren', 'description' => 'Zorg dat paarden elkaar gemakkelijk kunnen ontwijken en voldoende persoonlijke ruimte hebben. Voorkom nauwe doorgangen, doodlopende stukken en situaties waarin een paard door groepsgenoten kan worden ingesloten.'],
        ],
        'voeding_adviezen' => [
            ['title' => 'Langdurige ruwvoerpauzes voorkomen', 'description' => 'Zorg dat je paard gedurende de dag en nacht voldoende mogelijkheden heeft om ruwvoer op te nemen. Voorkom langdurige periodes zonder vezelrijk voer en stem de hoeveelheid en samenstelling af op de individuele behoeften, stofwisseling en gezondheid van je paard.'],
        ],
        'beweging_adviezen' => [
            ['title' => 'Natuurlijke beweging via terreininrichting stimuleren', 'description' => 'Richt de leefomgeving zo in dat je paard wordt uitgenodigd om regelmatig en op eigen tempo te bewegen. Verspreid voer, water, rust- en schuilmogelijkheden over het terrein en creëer veilige looproutes. Houd rekening met de lichamelijke belastbaarheid van je paard.'],
        ],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::ITEMS as $table => $items) {
                foreach ($items as $item) {
                    $exists = DB::table($table)->whereRaw('LOWER(TRIM(title)) = ?', [mb_strtolower(trim($item['title']))])->exists();
                    if (! $exists) {
                        DB::table($table)->insert($item + [
                            'id' => (string) Str::uuid(), 'layout' => 'normal', 'icon' => null,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Keep library advice: admins may have edited it or selected it in a protocol.
    }
};
