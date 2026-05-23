<?php

namespace Database\Seeders;

use App\Models\CommunityCategory;
use App\Models\CommunityTag;
use App\Models\FocusTopic;
use App\Models\Ingredient;
use App\Models\LibraryCategory;
use App\Models\NovaFallbackReply;
use App\Models\Plan;
use App\Models\PlanBenefit;
use Illuminate\Database\Seeder;

class ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedFocusTopics();
        $this->seedLibraryCategories();
        $this->seedCommunityCategories();
        $this->seedCommunityTags();
        $this->seedPlans();
        $this->seedIngredients();
        $this->seedNovaReplies();
    }

    private function seedFocusTopics(): void
    {
        $topics = [
            ['slug' => 'jeuk', 'icon' => '🌿', 'title' => 'Jeukklachten', 'description' => 'Huid, manen, staart'],
            ['slug' => 'staak', 'icon' => '🐎', 'title' => 'Staakgedrag', 'description' => 'Onder zadel of in stal'],
            ['slug' => 'darm', 'icon' => '💧', 'title' => 'Darmproblemen', 'description' => 'Mest, kolieken, gas'],
            ['slug' => 'prev', 'icon' => '✨', 'title' => 'Preventief', 'description' => 'Geen klachten, wel meer weten'],
            ['slug' => 'and', 'icon' => '✦', 'title' => 'Iets anders', 'description' => 'Vertel het in een vrij veld'],
            ['slug' => 'hoef', 'icon' => '🦶', 'title' => 'Hoeven', 'description' => 'Bevangenheid, rotstraal, scheuren'],
            ['slug' => 'gewicht', 'icon' => '⚖️', 'title' => 'Gewicht', 'description' => 'Te dik, te dun, conditie'],
        ];
        foreach ($topics as $i => $t) {
            FocusTopic::create([...$t, 'order' => $i]);
        }
    }

    private function seedLibraryCategories(): void
    {
        $cats = [
            ['slug' => 'aanbevolen', 'label' => 'Aanbevolen', 'is_default' => true],
            ['slug' => 'jeuk', 'label' => 'Voor jeuk'],
            ['slug' => 'darmen', 'label' => 'Voor darmen'],
            ['slug' => 'voeding', 'label' => 'Voeding'],
            ['slug' => 'kruiden', 'label' => 'Kruiden'],
            ['slug' => 'cursussen', 'label' => 'Cursussen'],
            ['slug' => 'gedrag', 'label' => 'Gedrag'],
            ['slug' => 'hoeven', 'label' => 'Hoeven'],
        ];
        foreach ($cats as $i => $c) {
            LibraryCategory::create([...$c, 'order' => $i]);
        }
    }

    private function seedCommunityCategories(): void
    {
        $cats = [
            ['slug' => 'alles', 'label' => 'Alles', 'is_default' => true],
            ['slug' => 'mijn-focus', 'label' => 'Mijn focus'],
            ['slug' => 'vraag-shelley', 'label' => 'Vraag Shelley'],
            ['slug' => 'reviews', 'label' => 'Reviews'],
            ['slug' => 'diensten', 'label' => 'Diensten'],
        ];
        foreach ($cats as $i => $c) {
            CommunityCategory::create([...$c, 'order' => $i]);
        }
    }

    private function seedCommunityTags(): void
    {
        foreach ([
            'jeukklachten', 'voeding', 'darmen', 'mest', 'huid', 'manen',
            'hoeven', 'gedrag', 'training', 'verhuizing', 'allergie',
            'kruiden', 'brandnetel', 'lijnzaad', 'preventief', 'gewicht',
        ] as $label) {
            CommunityTag::create(['slug' => $label, 'label' => $label]);
        }
    }

    private function seedPlans(): void
    {
        $free = Plan::create([
            'slug' => 'free', 'label' => 'Gratis', 'name' => 'EquiNova Free',
            'price_cents' => 0, 'currency' => 'EUR', 'interval' => 'monthly',
            'price_suffix' => '/ maand', 'description' => 'Voor wie wil rondkijken.',
            'is_recommended' => false, 'order' => 0,
        ]);
        foreach (['1 paard', 'Beperkte scans', 'Bibliotheek voorvertoning'] as $i => $b) {
            PlanBenefit::create(['plan_id' => $free->id, 'label' => $b, 'order' => $i]);
        }

        $plus = Plan::create([
            'slug' => 'plus', 'label' => 'Plus', 'name' => 'EquiNova Plus',
            'price_cents' => 1200, 'currency' => 'EUR', 'interval' => 'monthly',
            'price_suffix' => '/ maand', 'description' => 'Voor de actieve verzorger.',
            'is_recommended' => false, 'order' => 1,
        ]);
        foreach ([
            'Onbeperkte scans + AI-advies',
            'Toegang tot alle bibliotheek-content',
            'Direct vragen stellen aan Shelley',
            'Tot 3 paarden',
        ] as $i => $b) {
            PlanBenefit::create(['plan_id' => $plus->id, 'label' => $b, 'order' => $i]);
        }

        $bundle = Plan::create([
            'slug' => 'bundle', 'label' => 'Aanbevolen', 'name' => 'Opleiding bundel',
            'price_cents' => 499700, 'currency' => 'EUR', 'interval' => 'one_time',
            'price_suffix' => 'eenmalig', 'description' => 'EquiNova Plus + 8-maands opleiding.',
            'is_recommended' => true, 'order' => 2,
        ]);
        foreach ([
            'Alles uit Plus',
            '8 maanden begeleiding van Shelley',
            'Certificaat na afronding',
            'Toegang tot live workshops',
        ] as $i => $b) {
            PlanBenefit::create(['plan_id' => $bundle->id, 'label' => $b, 'order' => $i]);
        }
    }

    private function seedIngredients(): void
    {
        $ings = [
            ['Lijnzaad', 'good', 'Goede bron van omega-3 — past in een holistisch voerplan.'],
            ['Bierdrab', 'good', 'Natuurlijke B-vitaminen en aminozuren.'],
            ['Mout-extract', 'warn', 'Bevat suikers. Niet ideaal voor insuline-gevoelige paarden.'],
            ['Vit. C (synth.)', 'warn', 'Synthetische toevoeging — overweeg natuurlijke bron.'],
            ['Saccharose (E473)', 'danger', 'Toegevoegde suiker — vermijd bij metabole problemen.'],
            ['Brandnetel', 'good', 'Mild ontstekingsremmend, rijk aan silicium en ijzer.'],
            ['Mariadistel', 'good', 'Ondersteunt lever en nieren.'],
            ['Spirulina', 'good', 'Eiwitten en sporenelementen.'],
            ['Chia-zaad', 'good', 'Omega-3 en vezels.'],
            ['Tarwe (gemoffeld)', 'warn', 'Hoog in zetmeel — let op bij metabole problemen.'],
            ['Melasse', 'danger', 'Hoog suikergehalte.'],
            ['E-anti-oxidant (E321)', 'danger', 'Synthetische conserveermiddel.'],
            ['Sojameel', 'warn', 'Vaak GMO; allergeen voor sommige paarden.'],
            ['Luzerne', 'good', 'Eiwitrijk en smakelijk.'],
            ['Magnesium-oxide', 'good', 'Goed opneembaar mineraal.'],
            ['Selenium-gist', 'good', 'Beter opneembaar dan anorganisch selenium.'],
            ['Vitamine E (natuurlijk)', 'good', 'Anti-oxidant — vooral nuttig bij weinig vers gras.'],
            ['IJzersulfaat', 'warn', 'Vaak overgedoseerd; voorzichtigheid geboden.'],
            ['Suikerbietpulp (ongemelasseerd)', 'good', 'Vezels zonder extra suiker.'],
            ['Maïs', 'warn', 'Hoog in zetmeel; kan darmflora verstoren.'],
            ['Zonnebloemolie', 'warn', 'Te veel omega-6 in verhouding tot omega-3.'],
            ['Echinacea', 'good', 'Immuunondersteunend kruid.'],
            ['Kurkuma', 'good', 'Anti-inflammatoire werking.'],
            ['Knoflook (gedroogd)', 'warn', 'Hoge dosering kan bloed beïnvloeden.'],
            ['Probiotica-mengsel', 'good', 'Ondersteunt darmflora.'],
            ['Citroenzuur (E330)', 'warn', 'Conserveermiddel — kan maagslijmvlies prikkelen.'],
            ['Natriumchloride', 'good', 'Zout — essentieel bij zweten.'],
            ['Kelp (zeewier)', 'good', 'Mineralen en jodium.'],
            ['Kunstmatige kleurstof (E124)', 'danger', 'Onnodige toevoeging.'],
            ['Glucose-siroop', 'danger', 'Eenvoudige suiker — vermijd bij EMS/IR.'],
        ];
        foreach ($ings as [$name, $tag, $desc]) {
            Ingredient::create(['name' => $name, 'default_tag' => $tag, 'description' => $desc]);
        }
    }

    private function seedNovaReplies(): void
    {
        foreach ([
            'Goede vraag! Bij dit type klachten zou ik beginnen met het versterken van de darmen — zie het brandnetel-artikel.',
            'Let bij voorjaarsrui op de combinatie lijnzaad + brandnetel. Bouw langzaam op.',
            'Vergeet niet dat huid en darmen samen werken — het is écht een holistisch verhaal.',
            'Bij dit soort gedrag check ik altijd eerst de mest. Begin daar.',
            'Vraag het ook even na bij Shelley — zij heeft hier veel ervaring mee.',
            'Vermijd in deze fase plotselinge voerwijzigingen. Geleidelijk is altijd beter.',
            'Heb je al eens een mineralen-analyse laten doen? Soms ligt het daar.',
            'In mei en juni is brandnetel op zijn krachtigst. Pluk vers.',
            'Bij locatiewissel: probeer een paar handen ruwvoer mee te nemen, dat helpt de darmen.',
            'Goede observatie. Houd dit een week vol en kijk dan opnieuw.',
        ] as $i => $body) {
            NovaFallbackReply::create(['body' => $body, 'order' => $i]);
        }
    }
}
