<?php

namespace Database\Seeders;

use App\Models\FocusTopic;
use App\Models\LibraryArticleSection;
use App\Models\LibraryCategory;
use App\Models\LibraryChapter;
use App\Models\LibraryItem;
use App\Models\SeasonalTip;
use App\Models\Therapist;
use Illuminate\Database\Seeder;

class LibrarySeeder extends Seeder
{
    public function run(): void
    {
        $shelley = Therapist::where('name', 'Shelley')->first();
        $therapistIds = Therapist::pluck('id')->all();
        $categoriesBySlug = LibraryCategory::pluck('id', 'slug');
        $focusBySlug = FocusTopic::pluck('id', 'slug');

        // Hand-crafted featured + a few well-known items.
        $brandnetel = LibraryItem::create([
            'slug' => 'brandnetel',
            'format' => 'video',
            'title' => 'Brandnetel — 5 minuten over de juiste dosering',
            'description' => 'Hoeveel, hoe vaak en wat juist te vermijden.',
            'duration_label' => '5 min · Video',
            'duration_sec' => 324,
            'author_therapist_id' => $shelley->id,
            'views_label' => '1.2k gezien',
            'published_at' => now()->subDays(20),
            'is_plus' => false,
            'is_featured' => true,
            'order' => 0,
        ]);
        $this->seedChapters($brandnetel, [
            ['Wanneer brandnetel plukken', '0:00', 0],
            ['Verse vs. gedroogde — wat werkt', '1:14', 74],
            ['Doseren in vijf dagen', '2:32', 152],
            ['Wanneer niet te geven', '4:10', 250],
        ]);
        $this->seedSections($brandnetel, [
            ['', 'Brandnetel is in mei en juni op zijn krachtigst. De jonge blaadjes bevatten silicium, ijzer en een mild ontstekingsremmende werking — perfect bij voorjaars-jeuk en milde manenklachten.'],
            ['Hoeveel?', 'Begin met één eetlepel vers per dag, door het ruwvoer. Bouw in vijf dagen op naar 2–3 eetlepels, afhankelijk van het gewicht.'],
            ['Niet doen.', 'Geen gedroogde brandnetel zonder broeien — dit verstoort de werking. En niet langer dan zes weken aan een stuk: bouw daarna af.'],
        ]);
        $brandnetel->categories()->attach([$categoriesBySlug['kruiden'], $categoriesBySlug['aanbevolen']]);
        $brandnetel->focusTopics()->attach([$focusBySlug['jeuk'], $focusBySlug['darm']]);

        $knownTitles = [
            ['lijnzaad', 'article', 'Lijnzaad: doseren in 7 dagen', '8 min · Artikel', ['voeding', 'aanbevolen'], ['darm']],
            ['mest-score', 'video', 'Lees de mest van je paard', '6 min · Video', ['aanbevolen'], ['darm']],
            ['darmen-cursus', 'course', 'Darmen Cursus — Hoofdstuk 3 · 4 lessen', '42 min · Video', ['cursussen', 'darmen'], ['darm']],
            ['locatie', 'program', 'In balans bij locatiewissel', '4 weken · Programma', ['aanbevolen'], []],
            ['hoefb', 'article', 'Hoefbevangenheid — de eerste signalen', '7 min · Artikel', ['hoeven'], ['hoef']],
            ['zomereczeem', 'article', 'Zomereczeem — preventief beleid', '9 min · Artikel', ['jeuk'], ['jeuk']],
            ['krachtvoer', 'article', 'Krachtvoer zonder granen — waarom?', '6 min · Artikel', ['voeding'], ['darm']],
            ['mariadistel', 'article', 'Mariadistel voor de lever', '5 min · Artikel', ['kruiden'], []],
        ];
        foreach ($knownTitles as $i => [$slug, $format, $title, $duration, $catSlugs, $focusSlugs]) {
            $item = LibraryItem::create([
                'slug' => $slug,
                'format' => $format,
                'title' => $title,
                'description' => fake()->sentence(12),
                'duration_label' => $duration,
                'duration_sec' => match (true) {
                    str_contains($duration, 'min') => (int) filter_var($duration, FILTER_SANITIZE_NUMBER_INT) * 60,
                    default => 0,
                },
                'author_therapist_id' => fake()->randomElement($therapistIds),
                'views_label' => fake()->numberBetween(50, 5000) . ' gezien',
                'published_at' => now()->subDays(fake()->numberBetween(1, 200)),
                'is_plus' => fake()->boolean(20),
                'is_featured' => false,
                'order' => $i + 1,
            ]);
            if ($format === 'article') {
                $this->seedSections($item, [
                    ['', fake()->paragraph(5)],
                    ['Wat te doen?', fake()->paragraph(4)],
                    ['Niet doen.', fake()->paragraph(3)],
                ]);
            } elseif ($format === 'video' || $format === 'course') {
                $this->seedChapters($item, [
                    ['Introductie', '0:00', 0],
                    ['Belangrijkste signalen', '1:30', 90],
                    ['Praktische tips', '3:15', 195],
                    ['Samenvatting', '5:00', 300],
                ]);
            }
            $catIds = collect($catSlugs)->map(fn ($s) => $categoriesBySlug[$s])->all();
            if ($catIds) $item->categories()->attach($catIds);
            $focusIds = collect($focusSlugs)->map(fn ($s) => $focusBySlug[$s])->all();
            if ($focusIds) $item->focusTopics()->attach($focusIds);
        }

        // Fill out to ~50 items with generated content.
        $formats = ['article', 'video', 'course', 'program'];
        $allCatIds = $categoriesBySlug->values()->all();
        $allFocusIds = $focusBySlug->values()->all();
        for ($i = 0; $i < 40; $i++) {
            $format = fake()->randomElement($formats);
            $minutes = fake()->numberBetween(3, 30);
            $item = LibraryItem::create([
                'slug' => fake()->unique()->slug(3),
                'format' => $format,
                'title' => ucfirst(fake()->words(fake()->numberBetween(4, 8), true)),
                'description' => fake()->sentence(10),
                'duration_label' => $minutes . ' min · ' . ucfirst($format),
                'duration_sec' => $minutes * 60,
                'author_therapist_id' => fake()->randomElement($therapistIds),
                'views_label' => fake()->numberBetween(10, 8000) . ' gezien',
                'published_at' => now()->subDays(fake()->numberBetween(1, 400)),
                'is_plus' => fake()->boolean(30),
                'is_featured' => false,
                'order' => 100 + $i,
            ]);
            if ($format === 'article') {
                for ($s = 0; $s < fake()->numberBetween(2, 5); $s++) {
                    LibraryArticleSection::create([
                        'item_id' => $item->id,
                        'order' => $s,
                        'heading' => $s === 0 ? '' : ucfirst(fake()->words(3, true)) . '.',
                        'body' => fake()->paragraph(fake()->numberBetween(3, 7)),
                    ]);
                }
            } else {
                $minutes = max(2, $minutes);
                for ($c = 0; $c < min(6, $minutes); $c++) {
                    $sec = (int) ($c * ($minutes * 60 / $minutes));
                    LibraryChapter::create([
                        'item_id' => $item->id,
                        'order' => $c,
                        'title' => ucfirst(fake()->words(fake()->numberBetween(3, 6), true)),
                        'start_label' => sprintf('%d:%02d', intdiv($sec, 60), $sec % 60),
                        'start_sec' => $sec,
                    ]);
                }
            }
            $item->categories()->attach(fake()->randomElements($allCatIds, fake()->numberBetween(1, 3)));
            $item->focusTopics()->attach(fake()->randomElements($allFocusIds, fake()->numberBetween(0, 2)));
        }

        // Seasonal tips — one per month, current month active.
        $months = ['januari','februari','maart','april','mei','juni','juli','augustus','september','oktober','november','december'];
        $current = (int) now()->format('n');
        foreach ($months as $i => $m) {
            SeasonalTip::create([
                'month' => $m,
                'month_order' => $i + 1,
                'body' => fake()->paragraph(3),
                'cta_item_id' => $i === 4 ? $brandnetel->id : null,
                'active' => $i + 1 === $current,
            ]);
        }
    }

    private function seedChapters(LibraryItem $item, array $rows): void
    {
        foreach ($rows as $i => [$title, $label, $sec]) {
            LibraryChapter::create([
                'item_id' => $item->id,
                'order' => $i,
                'title' => $title,
                'start_label' => $label,
                'start_sec' => $sec,
            ]);
        }
    }

    private function seedSections(LibraryItem $item, array $rows): void
    {
        foreach ($rows as $i => [$heading, $body]) {
            LibraryArticleSection::create([
                'item_id' => $item->id,
                'order' => $i,
                'heading' => $heading,
                'body' => $body,
            ]);
        }
    }
}
