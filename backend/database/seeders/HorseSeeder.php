<?php

namespace Database\Seeders;

use App\Models\FocusTopic;
use App\Models\Horse;
use App\Models\HorseShare;
use App\Models\HorseStat;
use App\Models\Observation;
use App\Models\Protocol;
use App\Models\ProtocolAdvice;
use App\Models\ProtocolAnalysis;
use App\Models\ProtocolPhase;
use App\Models\ProtocolPhaseItem;
use App\Models\ProtocolTask;
use App\Models\ProtocolTaskCompletion;
use App\Models\ProtocolType;
use App\Models\Therapist;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Database\Seeder;

class HorseSeeder extends Seeder
{
    private const HORSE_NAMES = [
        'Nova', 'Pip', 'Storm', 'Sterre', 'Daphne', 'Rosa', 'Bonita', 'Mickey',
        'Quinta', 'Tijger', 'Bliksem', 'Donder', 'Pippi', 'Romeo', 'Juliette',
        'Apollo', 'Zazu', 'Lola', 'Hugo', 'Sam', 'Aslan', 'Beau', 'Cleo',
        'Diva', 'Echo', 'Felix', 'Gypsy', 'Hera', 'Indy', 'Jazz',
    ];

    private const BREEDS = [
        'KWPN', 'Friese kruising', 'Welsh pony', 'Haflinger', 'Shetlander',
        'Arabier', 'New Forest', 'IJslander', 'Tinker', 'Lusitano',
        'Andalusiër', 'Quarter Horse', 'Connemara',
    ];

    public function run(): void
    {
        $anchorUser = User::where('email', UserSeeder::ANCHOR_EMAIL)->firstOrFail();
        $shelley = Therapist::where('name', 'Shelley')->first();
        $therapistIds = Therapist::pluck('id')->all();
        $focusIds = FocusTopic::pluck('id')->all();
        $focusBySlug = FocusTopic::pluck('id', 'slug');

        // Seed the anchor user's hand-crafted horses first.
        $nova = Horse::create([
            'owner_id' => $anchorUser->id,
            'name' => 'Nova',
            'breed' => 'Friese kruising',
            'age' => 9,
            'sex' => 'merrie',
            'weight_kg' => 540,
            'stable' => 'Manege De Hoeve · Box 4',
            'status' => 'active',
            'created_at' => now()->subWeeks(3),
        ]);
        $nova->focusTopics()->attach([$focusBySlug['jeuk'], $focusBySlug['darm']], [
            'extra_label' => 'Jeukklachten', 'added_at' => now()->subWeeks(3),
        ]);
        $this->seedNovaSpecifics($nova, $anchorUser, $shelley);

        Horse::create([
            'owner_id' => $anchorUser->id,
            'name' => 'Pip',
            'breed' => 'Welsh pony',
            'age' => 16,
            'sex' => 'ruin',
            'status' => 'archived',
            'archived_at' => '2024-01-01',
            'archived_note' => 'Gearchiveerd · in 2024 overleden',
        ]);

        // Random horses for everyone else.
        $otherUsers = User::where('id', '!=', $anchorUser->id)->get();
        foreach ($otherUsers as $user) {
            $count = fake()->numberBetween(1, 3);
            for ($i = 0; $i < $count; $i++) {
                $horse = Horse::create([
                    'owner_id' => $user->id,
                    'name' => fake()->randomElement(self::HORSE_NAMES),
                    'breed' => fake()->randomElement(self::BREEDS),
                    'age' => fake()->numberBetween(3, 24),
                    'sex' => fake()->randomElement(['merrie', 'ruin', 'hengst']),
                    'weight_kg' => fake()->numberBetween(280, 680),
                    'stable' => fake()->company().' · Box '.fake()->numberBetween(1, 30),
                    'status' => 'active',
                    'created_at' => fake()->dateTimeBetween('-9 months', '-1 week'),
                ]);
                $picks = fake()->randomElements($focusIds, fake()->numberBetween(0, 3));
                foreach ($picks as $fid) {
                    $horse->focusTopics()->attach($fid, ['added_at' => $horse->created_at]);
                }
                $this->seedStats($horse);
                $this->seedTimeline($horse);
                if (fake()->boolean(40)) {
                    $this->seedShares($horse, $shelley, $therapistIds);
                }
                if (fake()->boolean(70)) {
                    $this->seedProtocol($horse, fake()->randomElement($therapistIds));
                }
                $this->seedObservations($horse, $user);
            }
        }
    }

    private function seedNovaSpecifics(Horse $nova, User $owner, Therapist $shelley): void
    {
        HorseShare::create([
            'horse_id' => $nova->id, 'therapist_id' => $shelley->id, 'role' => 'full', 'since' => now()->subWeeks(3),
        ]);

        $stats = [
            ['Gewicht', '540 kg', 'stabiel', 540, null, null],
            ['Energie', '7 / 10', '↑ +1 deze week', null, 7, null],
            ['Mest-score', 'B+', 'stabiel', null, null, 'B+'],
        ];
        foreach ($stats as $i => [$label, $value, $trend, $w, $e, $s]) {
            HorseStat::create([
                'horse_id' => $nova->id, 'measured_at' => now(), 'label' => $label,
                'value_label' => $value, 'trend' => $trend,
                'weight_kg' => $w, 'energy' => $e, 'stool_score' => $s,
                'order' => $i,
            ]);
        }

        $timeline = [
            ['vandaag', 'Brandnetel toegevoegd aan protocol (mei-seizoenstip)', true, 'protocol_change'],
            ['3 dagen geleden', 'Foto van mest geüpload — Score B+', false, 'observation'],
            ['2 weken geleden', 'Intake met Shelley · jeukklachten + spijsvertering', false, 'intake'],
            ['3 weken geleden', 'Nova toegevoegd aan EquiNova', false, 'horse_added'],
        ];
        foreach ($timeline as $i => [$when, $msg, $now, $kind]) {
            TimelineEvent::create([
                'horse_id' => $nova->id,
                'occurred_at' => now()->subDays($i * 4),
                'when_label' => $when, 'kind' => $kind, 'message' => $msg,
                'is_now' => $now, 'order' => $i,
            ]);
        }

        for ($i = 0; $i < 12; $i++) {
            Observation::create([
                'horse_id' => $nova->id,
                'author_id' => $owner->id,
                'date' => now()->subDays($i * 2 + 1)->toDateString(),
                'note' => fake()->randomElement([
                    'Mest iets losser, lijnzaad teruggebracht.',
                    'Minder krabben aan manen vandaag.',
                    'Energie goed, soepel onder zadel.',
                    'Lichte irritatie staart, niet erger.',
                    'Goed gegeten, foto van mest gemaakt.',
                ]),
                'mood' => fake()->numberBetween(3, 5),
                'stool_score' => fake()->randomElement(['A', 'B+', 'B', 'B']),
            ]);
        }
    }

    private function seedStats(Horse $horse): void
    {
        $energy = fake()->numberBetween(4, 9);
        $stool = fake()->randomElement(['A', 'B+', 'B', 'C', 'B+']);
        $stats = [
            ['Gewicht', $horse->weight_kg.' kg', fake()->randomElement(['stabiel', '↑ +5 kg', '↓ -3 kg']), $horse->weight_kg, null, null],
            ['Energie', $energy.' / 10', fake()->randomElement(['stabiel', '↑ +1', '↓ -1']), null, $energy, null],
            ['Mest-score', $stool, 'stabiel', null, null, $stool],
        ];
        foreach ($stats as $i => [$label, $value, $trend, $w, $e, $s]) {
            HorseStat::create([
                'horse_id' => $horse->id, 'measured_at' => now()->subDays(fake()->numberBetween(0, 5)),
                'label' => $label, 'value_label' => $value, 'trend' => $trend,
                'weight_kg' => $w, 'energy' => $e, 'stool_score' => $s, 'order' => $i,
            ]);
        }
    }

    private function seedTimeline(Horse $horse): void
    {
        $kinds = ['protocol_change', 'observation', 'intake', 'horse_added', 'scan'];
        $events = fake()->numberBetween(3, 8);
        for ($i = 0; $i < $events; $i++) {
            $dt = now()->subDays($i * fake()->numberBetween(1, 7));
            TimelineEvent::create([
                'horse_id' => $horse->id,
                'occurred_at' => $dt,
                'when_label' => $i === 0 ? 'vandaag' : ($i === 1 ? 'gisteren' : $i.' dagen geleden'),
                'kind' => fake()->randomElement($kinds),
                'message' => fake()->sentence(8),
                'is_now' => $i === 0,
                'order' => $i,
            ]);
        }
    }

    private function seedShares(Horse $horse, Therapist $shelley, array $therapistIds): void
    {
        HorseShare::create([
            'horse_id' => $horse->id,
            'therapist_id' => fake()->randomElement($therapistIds),
            'role' => 'full',
            'since' => fake()->dateTimeBetween('-9 months', '-1 week'),
        ]);
        if (fake()->boolean(35)) {
            $coCarer = User::inRandomOrder()->where('id', '!=', $horse->owner_id)->first();
            if ($coCarer) {
                HorseShare::create([
                    'horse_id' => $horse->id,
                    'grantee_user_id' => $coCarer->id,
                    'role' => 'read_only',
                    'since' => fake()->dateTimeBetween('-3 months', '-1 week'),
                ]);
            }
        }
    }

    private function seedProtocol(Horse $horse, string $therapistId): void
    {
        $protocolType = ProtocolType::query()->firstOrCreate(['name' => 'Darm protocol']);
        $phaseDefinitions = $protocolType->phases()->orderBy('order')->get();
        while ($phaseDefinitions->count() < 3) {
            $phaseNumber = $phaseDefinitions->count() + 1;
            $phaseDefinitions->push($protocolType->phases()->create([
                'order' => ((int) $phaseDefinitions->max('order')) + 1,
                'name' => "Fase {$phaseNumber}",
                'description' => null,
                'required' => $phaseNumber === 1,
            ]));
        }
        $totalWeeks = fake()->numberBetween(4, 12);
        $currentWeek = fake()->numberBetween(1, $totalWeeks);
        $phaseLengths = array_fill(0, 3, intdiv($totalWeeks, 3));
        for ($i = 0; $i < $totalWeeks % 3; $i++) {
            $phaseLengths[$i]++;
        }

        $activePhaseNumber = 1;
        $weekCursor = 1;
        foreach ($phaseLengths as $index => $phaseLength) {
            $weekEnd = $weekCursor + $phaseLength - 1;
            if ($currentWeek >= $weekCursor && $currentWeek <= $weekEnd) {
                $activePhaseNumber = $index + 1;
                break;
            }
            $weekCursor = $weekEnd + 1;
        }

        $protocol = Protocol::create([
            'horse_id' => $horse->id, 'therapist_id' => $therapistId,
            'protocol_type_id' => $protocolType->id,
            'title' => $horse->name."'s plan",
            'subtitle_protocol' => "Week {$currentWeek} van {$totalWeeks} · Fase {$activePhaseNumber} actief",
            'subtitle_analyse' => $horse->breed.' · '.fake()->randomElement(['Jeuk', 'Darmen', 'Hoeven', 'Allergie']),
            'subtitle_calendar' => 'Mei '.now()->year,
            'total_weeks' => $totalWeeks, 'current_week' => $currentWeek,
            'started_at' => now()->subWeeks($currentWeek), 'status' => 'active',
        ]);

        $activePhase = null;
        $weekCursor = 1;
        foreach ($phaseLengths as $i => $phaseLength) {
            $weekEnd = $weekCursor + $phaseLength - 1;
            $state = $currentWeek > $weekEnd ? 'done' : ($currentWeek >= $weekCursor ? 'active' : 'upcoming');
            $phase = ProtocolPhase::create([
                'protocol_id' => $protocol->id, 'protocol_type_phase_id' => $phaseDefinitions[$i]->id, 'order' => $i,
                'title' => 'Fase '.($i + 1).' — '.fake()->randomElement(['Darmen', 'Lever', 'Huid', 'Hoeven']),
                'state' => $state,
                'week_start' => $weekCursor, 'week_end' => $weekEnd,
                'chip_label' => $state === 'done' ? 'Klaar' : ($state === 'active' ? 'Actief' : 'Komende'),
            ]);
            if ($state === 'active') {
                $activePhase = $phase;
            }
            for ($j = 0; $j < fake()->numberBetween(2, 5); $j++) {
                ProtocolPhaseItem::create(['phase_id' => $phase->id, 'order' => $j, 'label' => fake()->sentence(6)]);
            }
            $weekCursor = $weekEnd + 1;
        }

        if (! $activePhase) {
            return;
        }

        $analysis = ProtocolAnalysis::create(['protocol_id' => $protocol->id, 'cause' => fake()->paragraph(3)]);
        foreach (['leaf', 'run', 'horse'] as $i => $icon) {
            ProtocolAdvice::create([
                'analysis_id' => $analysis->id, 'icon_key' => $icon,
                'title' => ['leaf' => 'Voeding', 'run' => 'Management', 'horse' => 'Training'][$icon],
                'body' => fake()->sentence(12), 'order' => $i,
            ]);
        }

        $taskCount = fake()->numberBetween(3, 6);
        $taskIds = [];
        for ($i = 0; $i < $taskCount; $i++) {
            $task = ProtocolTask::create([
                'protocol_id' => $protocol->id, 'phase_id' => $activePhase->id,
                'label' => fake()->sentence(5),
                'meta' => fake()->randomElement(['Ochtendvoer', 'Avondvoer', 'Na ochtendmest', 'Vóór beweging', 'Bij opstallen']),
                'kind' => fake()->randomElement(['feeding', 'observation', 'care', 'other']),
                'order' => $i, 'active_from' => $protocol->started_at,
            ]);
            $taskIds[] = $task->id;
        }
        for ($d = 0; $d < 10; $d++) {
            $date = now()->subDays($d)->toDateString();
            foreach ($taskIds as $tid) {
                $done = $d > 1 ? fake()->boolean(85) : fake()->boolean(50);
                ProtocolTaskCompletion::create([
                    'task_id' => $tid, 'horse_id' => $horse->id, 'date' => $date,
                    'done' => $done, 'done_at' => $done ? now()->subDays($d) : null,
                ]);
            }
        }
    }

    private function seedObservations(Horse $horse, User $author): void
    {
        $count = fake()->numberBetween(2, 12);
        for ($i = 0; $i < $count; $i++) {
            Observation::create([
                'horse_id' => $horse->id,
                'author_id' => $author->id,
                'date' => fake()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
                'note' => fake()->sentence(fake()->numberBetween(6, 15)),
                'mood' => fake()->numberBetween(2, 5),
                'stool_score' => fake()->randomElement(['A', 'B+', 'B', 'C', null]),
            ]);
        }
    }
}
