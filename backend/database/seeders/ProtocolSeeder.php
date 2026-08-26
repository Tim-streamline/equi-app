<?php

namespace Database\Seeders;

use App\Models\Horse;
use App\Models\Protocol;
use App\Models\ProtocolAdvice;
use App\Models\ProtocolAnalysis;
use App\Models\ProtocolPhase;
use App\Models\ProtocolPhaseWeek;
use App\Models\ProtocolTemplate;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProtocolSeeder extends Seeder
{
    private const ANCHOR_HORSE = 'Nova';

    private const ANCHOR_PROTOCOL = "Nova's plan";

    public function run(): void
    {
        $anchorUser = User::query()
            ->where('email', UserSeeder::ANCHOR_EMAIL)
            ->firstOrFail();
        $horse = $anchorUser->horses()
            ->where('name', self::ANCHOR_HORSE)
            ->firstOrFail();
        $therapist = Therapist::query()
            ->where('name', 'Shelley')
            ->firstOrFail();

        DB::transaction(fn () => $this->seedAnchorProtocol($horse, $therapist));
    }

    private function seedAnchorProtocol(Horse $horse, Therapist $therapist): void
    {
        $protocolTemplate = ProtocolTemplate::query()->firstOrCreate(['name' => 'Darm protocol']);
        $phaseDefinitions = $protocolTemplate->phases()->orderBy('order')->get();
        while ($phaseDefinitions->count() < 3) {
            $phaseNumber = $phaseDefinitions->count() + 1;
            $phaseDefinitions->push($protocolTemplate->phases()->create([
                'order' => ((int) $phaseDefinitions->max('order')) + 1,
                'name' => "Fase {$phaseNumber}",
                'description' => null,
                'required' => $phaseNumber === 1,
            ]));
        }
        foreach ([4, 2, 2] as $phaseIndex => $weekCount) {
            if ($phaseDefinitions[$phaseIndex]->weeks()->exists()) {
                continue;
            }
            foreach (range(1, $weekCount) as $number) {
                $phaseDefinitions[$phaseIndex]->weeks()->create(['number' => $number]);
            }
        }
        $startedAt = now()->subWeeks(3);
        $protocol = Protocol::query()->updateOrCreate(
            [
                'horse_id' => $horse->id,
                'title' => self::ANCHOR_PROTOCOL,
            ],
            [
                'protocol_template_id' => $protocolTemplate->id,
                'protocol_template_name' => $protocolTemplate->name,
                'therapist_id' => $therapist->id,
                'subtitle_analyse' => 'KWPN merrie · Jeuk / Zomereczeem',
                'subtitle_protocol' => 'Week 3 van 8 · Fase 1 actief',
                'subtitle_calendar' => 'Mei '.now()->year,
                'total_weeks' => 8,
                'current_week' => 3,
                'started_at' => $startedAt,
                'status' => 'active',
                'published_at' => now(),
            ],
        );

        $phases = [
            ['Fase 1 — Darmen', 'active', 1, 4, 'Actief · wk 1–4'],
            ['Fase 2 — Lever en nieren', 'upcoming', 5, 6, 'Vanaf wk 5'],
            ['Fase 3 — Huid', 'upcoming', 7, 8, 'Vanaf wk 7'],
        ];
        $activePhase = null;
        $phaseIds = [];

        foreach ($phases as $order => [$title, $state, $weekStart, $weekEnd, $chipLabel]) {
            $phase = ProtocolPhase::query()->updateOrCreate(
                ['protocol_id' => $protocol->id, 'order' => $order],
                [
                    'protocol_template_phase_id' => $phaseDefinitions[$order]->id,
                    'title' => $title,
                    'description' => $phaseDefinitions[$order]->description,
                    'required' => $phaseDefinitions[$order]->required,
                    'state' => $state,
                    'week_start' => $weekStart,
                    'week_end' => $weekEnd,
                    'chip_label' => $chipLabel,
                ],
            );
            $phaseIds[] = $phase->id;
            $weekIds = [];
            foreach (range(1, $weekEnd - $weekStart + 1) as $number) {
                $week = ProtocolPhaseWeek::query()->updateOrCreate(
                    ['protocol_phase_id' => $phase->id, 'number' => $number],
                    [
                        'protocol_template_phase_week_id' => $phaseDefinitions[$order]->weeks()->where('number', $number)->value('id'),
                        'protocol_week_number' => $weekStart + $number - 1,
                    ],
                );
                $weekIds[] = $week->id;
            }
            $phase->weeks()->whereNotIn('id', $weekIds)->delete();
            if ($state === 'active') {
                $activePhase = $phase;
            }
        }

        $protocol->phases()->whereNotIn('id', $phaseIds)->delete();

        $analysis = ProtocolAnalysis::query()->updateOrCreate(
            ['protocol_id' => $protocol->id],
            [
                'cause' => 'Nova heeft tekenen van een overbelast immuunsysteem door een verstoorde darmflora. De jeuk is niet het echte probleem. Het is een signaal van binnenuit.',
            ],
        );

        foreach ([
            ['leaf', 'Voeding', 'Krachtvoer met granen vervangen. Ruwvoer onbeperkt. Lijnzaad toevoegen.'],
            ['run', 'Management', 'Minimaal 6 uur bewegingsvrijheid per dag. Nachtbeweging indien mogelijk.'],
            ['horse', 'Training', 'Eerste 4 weken lichte belasting. Geen wedstrijdvoorbereiding tijdens fase 1.'],
        ] as $order => [$icon, $title, $body]) {
            ProtocolAdvice::query()->updateOrCreate(
                ['analysis_id' => $analysis->id, 'order' => $order],
                ['icon_key' => $icon, 'title' => $title, 'body' => $body],
            );
        }

    }
}
