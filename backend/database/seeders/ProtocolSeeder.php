<?php

namespace Database\Seeders;

use App\Models\Horse;
use App\Models\Protocol;
use App\Models\ProtocolAdvice;
use App\Models\ProtocolAnalysis;
use App\Models\ProtocolPhase;
use App\Models\ProtocolPhaseItem;
use App\Models\ProtocolTask;
use App\Models\ProtocolTaskCompletion;
use App\Models\ProtocolType;
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
        $protocolType = ProtocolType::query()->firstOrCreate(['name' => 'Darm protocol']);
        $startedAt = now()->subWeeks(3);
        $protocol = Protocol::query()->updateOrCreate(
            [
                'horse_id' => $horse->id,
                'title' => self::ANCHOR_PROTOCOL,
            ],
            [
                'protocol_type_id' => $protocolType->id,
                'therapist_id' => $therapist->id,
                'subtitle_analyse' => 'KWPN merrie · Jeuk / Zomereczeem',
                'subtitle_protocol' => 'Week 3 van 8 · Fase 1 actief',
                'subtitle_calendar' => 'Mei '.now()->year,
                'total_weeks' => 8,
                'current_week' => 3,
                'started_at' => $startedAt,
                'status' => 'active',
            ],
        );

        $phases = [
            ['Fase 1 — Darmen', 'active', 1, 4, 'Actief · wk 1–4', [
                '1 el brandnetel door ruwvoer (ochtend)',
                '1 el lijnzaad door ruwvoer (ochtend)',
                'Krachtvoer met granen weglaten',
                'Mest observeren en noteren',
            ]],
            ['Fase 2 — Lever en nieren', 'upcoming', 5, 6, 'Vanaf wk 5', []],
            ['Fase 3 — Huid', 'upcoming', 7, 8, 'Vanaf wk 7', []],
        ];
        $activePhase = null;
        $phaseIds = [];

        foreach ($phases as $order => [$title, $state, $weekStart, $weekEnd, $chipLabel, $items]) {
            $phase = ProtocolPhase::query()->updateOrCreate(
                ['protocol_id' => $protocol->id, 'order' => $order],
                [
                    'title' => $title,
                    'state' => $state,
                    'week_start' => $weekStart,
                    'week_end' => $weekEnd,
                    'chip_label' => $chipLabel,
                ],
            );
            $phaseIds[] = $phase->id;
            $itemIds = [];

            foreach ($items as $itemOrder => $label) {
                $item = ProtocolPhaseItem::query()->updateOrCreate(
                    ['phase_id' => $phase->id, 'order' => $itemOrder],
                    ['label' => $label],
                );
                $itemIds[] = $item->id;
            }

            if ($itemIds === []) {
                $phase->items()->delete();
            } else {
                $phase->items()->whereNotIn('id', $itemIds)->delete();
            }

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

        $tasks = [
            ['1 el brandnetel door ruwvoer', 'Ochtendvoer', 'feeding'],
            ['1 el lijnzaad door ruwvoer', 'Ochtendvoer', 'feeding'],
            ['Foto van mest in app loggen', 'Na ochtendmest', 'observation'],
            ['5 min borstelen rond manen', 'Vóór beweging', 'care'],
        ];

        foreach ($tasks as $order => [$label, $meta, $kind]) {
            $task = ProtocolTask::query()->updateOrCreate(
                ['protocol_id' => $protocol->id, 'order' => $order],
                [
                    'phase_id' => $activePhase?->id,
                    'label' => $label,
                    'meta' => $meta,
                    'kind' => $kind,
                    'active_from' => $startedAt,
                ],
            );

            for ($daysAgo = 0; $daysAgo < 14; $daysAgo++) {
                $date = now()->subDays($daysAgo);
                $done = $daysAgo > 1 || $daysAgo === 0;

                ProtocolTaskCompletion::query()->updateOrCreate(
                    ['task_id' => $task->id, 'date' => $date->toDateString()],
                    [
                        'horse_id' => $horse->id,
                        'done' => $done,
                        'done_at' => $done ? $date : null,
                    ],
                );
            }
        }
    }
}
