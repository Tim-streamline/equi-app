<?php

namespace Tests\Feature;

use App\Models\BewegingAdvies;
use App\Models\Horse;
use App\Models\ManagementAdvies;
use App\Models\Protocol;
use App\Models\Therapist;
use App\Models\User;
use App\Models\VoedingAdvies;
use Database\Seeders\BewegingAdviesSeeder;
use Database\Seeders\ManagementAdviesSeeder;
use Database\Seeders\ProtocolSeeder;
use Database\Seeders\UserSeeder;
use Database\Seeders\VoedingAdviesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtocolAdviceCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_additional_advice_is_idempotent_and_preserves_existing_edits(): void
    {
        $advice = ManagementAdvies::where('title', 'Wateropname controleren')->sole();
        $advice->update(['title' => ' WATEROPNAME CONTROLEREN ', 'description' => 'Eigen bibliotheektekst']);
        $before = [VoedingAdvies::count(), ManagementAdvies::count(), BewegingAdvies::count()];
        $migration = require database_path('migrations/2026_11_11_000001_add_equiboost_standard_advice.php');
        $migration->up();
        $migration->up();
        $this->assertSame($before, [VoedingAdvies::count(), ManagementAdvies::count(), BewegingAdvies::count()]);
        $this->assertSame('Eigen bibliotheektekst', $advice->fresh()->description);
        $this->assertDatabaseHas('voeding_adviezen', ['title' => 'Langdurige ruwvoerpauzes voorkomen']);
        $this->assertDatabaseHas('beweging_adviezen', ['title' => 'Natuurlijke beweging via terreininrichting stimuleren']);
    }

    public function test_the_design_advice_catalogs_are_seeded_idempotently(): void
    {
        $seeders = [VoedingAdviesSeeder::class, ManagementAdviesSeeder::class, BewegingAdviesSeeder::class];
        $counts = fn (): array => [VoedingAdvies::count(), ManagementAdvies::count(), BewegingAdvies::count()];

        $expectedTitles = [
            VoedingAdvies::class => [
                'Ruwvoer als basis',
                'Graszaadhooi beperken',
                'Stoppen met voordroog',
                'Waterkwaliteit controleren',
                'Zuivere balancer geven',
                'Geef enkel zuivere bijvoeding',
                'Stoppen met huidige bijvoeding',
                'Bij gewichtsverlies of gebitsproblemen',
                'Geen fruit, wortels of snoepjes',
                'Vers groen ter vrije selectie',
                'Slowfeeders met kleine mazen',
                'Voerwissels geleidelijk doorvoeren',
                'Geen gehakseld ruwvoer (fibermix)',
                'Zoutsteen ter vrije beschikking',
            ],
            ManagementAdvies::class => [
                'Weidebeleid',
                'Geen weidegang tijdens protocol',
                'Voerplekken',
                'Hoefverzorging',
                'Gebitscontrole',
                'Fysieke behandelingen',
                'Mestonderzoek',
                'Bloedonderzoek bij vermoeden IR',
                'Urineonderzoek bij vermoeden KPU',
            ],
            BewegingAdvies::class => [
                'Dagelijkse vrije beweging',
                'Regelmatige arbeid',
                'Bewegen zonder trainingsdoel',
                'Extra aandacht bij bewegingsproblemen',
                'Vrij spel en hersenwerk',
                'Longeren en volte beperken',
            ],
        ];
        $existingTitles = [];
        foreach ($expectedTitles as $model => $titles) {
            $existingTitles[$model] = $model::pluck('title')->all();
        }
        $this->seed($seeders);
        foreach ($expectedTitles as $model => $titles) {
            $this->assertEqualsCanonicalizing(
                array_values(array_unique([...$existingTitles[$model], ...$titles])),
                $model::pluck('title')->all(),
                'First seed must install the complete catalog without duplicates.',
            );
        }
        $seeded = $counts();
        $this->seed($seeders);

        $this->assertSame($seeded, $counts());

        $this->assertDatabaseHas('voeding_adviezen', [
            'title' => 'Ruwvoer als basis',
            'description' => 'Laat het rantsoen hoofdzakelijk bestaan uit onverpakt hooi (uit touwtjes). Geef 2 tot 3 kg hooi per 100 kg (gewenst) lichaamsgewicht en periodes zonder ruwvoer >2u (inclusief de nacht).',
            'layout' => 'normal',
        ]);
        $this->assertDatabaseHas('management_adviezen', [
            'title' => 'Bloedonderzoek bij vermoeden IR',
            'layout' => 'normal',
        ]);
        $this->assertDatabaseHas('beweging_adviezen', [
            'title' => 'Extra aandacht bij bewegingsproblemen',
            'layout' => 'normal',
        ]);
    }

    public function test_the_anchor_protocol_snapshots_every_seeded_design_item(): void
    {
        $anchorUser = User::query()->create([
            'name' => 'Shelley Meeuwsen',
            'email' => UserSeeder::ANCHOR_EMAIL,
            'password' => 'password',
        ]);
        $horse = Horse::query()->create([
            'owner_id' => $anchorUser->id,
            'name' => 'Nova',
            'breed' => 'Friese kruising',
            'age' => 9,
            'sex' => 'merrie',
            'status' => 'active',
        ]);
        Therapist::query()->create([
            'name' => 'Shelley',
            'title' => 'De Paardentherapeut',
            'verified' => true,
        ]);

        $this->seed([
            VoedingAdviesSeeder::class,
            ManagementAdviesSeeder::class,
            BewegingAdviesSeeder::class,
            ProtocolSeeder::class,
        ]);

        $protocol = Protocol::query()
            ->whereBelongsTo($horse)
            ->with(['voedingAdviezen', 'managementAdviezen', 'bewegingAdviezen'])
            ->sole();

        $this->assertCount(VoedingAdvies::query()->count(), $protocol->voedingAdviezen);
        $this->assertCount(ManagementAdvies::query()->count(), $protocol->managementAdviezen);
        $this->assertCount(BewegingAdvies::query()->count(), $protocol->bewegingAdviezen);
    }
}
