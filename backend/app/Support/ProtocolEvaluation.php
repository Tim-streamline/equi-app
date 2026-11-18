<?php

namespace App\Support;

use App\Models\PlusPage;
use App\Models\Protocol;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ProtocolEvaluation
{
    public const QUESTIONS = [
        'c12e1000-0000-4000-8000-000000000001' => 'Hoe gaat het nu met je paard? Welke veranderingen merk je sinds de start?',
        'c12e1000-0000-4000-8000-000000000002' => 'Welke oorspronkelijke klachten zijn verbeterd, gelijk gebleven of verergerd? Zijn er nieuwe klachten?',
        'c12e1000-0000-4000-8000-000000000003' => 'Hoe is het gelukt om de adviezen over voeding, beweging, huisvesting en management uit te voeren?',
        'c12e1000-0000-4000-8000-000000000004' => 'Hoe is het geven van de kruiden en producten gegaan? Wat heb je wel of niet kunnen geven?',
        'c12e1000-0000-4000-8000-000000000005' => 'Wat werkte goed en wat vond je lastig tijdens het herstelplan?',
        'c12e1000-0000-4000-8000-000000000006' => 'Waar wil je de komende periode aan werken? Wat wil je met je therapeut bespreken?',
    ];

    public function questions(Protocol $protocol): array
    {
        $snapshot = DB::table('protocol_evaluations')->where('protocol_id', $protocol->id)->value('questions');
        return $snapshot ? json_decode($snapshot, true) : DB::table('protocol_evaluation_questions')
            ->where('active', true)->orderBy('order')->orderBy('id')->pluck('label', 'id')->all();
    }

    public function questionVersion(array $questions): string
    {
        return hash('sha256', json_encode($questions));
    }

    public function timing(Protocol $protocol, CarbonImmutable $now): array
    {
        // Use one business timezone for the offer; client timezone cannot extend a discount.
        $today = $now->setTimezone('Europe/Amsterdam')->startOfDay();
        $protocol->loadMissing('phases.weeks');
        $weeks = max((int) $protocol->total_weeks, (int) $protocol->phases->flatMap->weeks->max('protocol_week_number'));
        $end = $protocol->started_at && $weeks > 0
            ? CarbonImmutable::parse($protocol->started_at->toDateString(), 'Europe/Amsterdam')->addWeeks($weeks)->subDay() : null;
        $available = $end && $protocol->published_at && $protocol->published_at->lte($now)
            && in_array($protocol->status, ['active', 'completed'], true) && $today->gte($end->subDays(14));
        return ['available' => (bool) $available, 'endsOn' => $end?->toDateString(),
            'daysRemaining' => $end ? (int) $today->diffInDays($end, false) : null,
            'discountUntil' => $end?->addDays(7)->toDateString(),
            'discountEligible' => (bool) ($available && $today->lte($end->addDays(7)))];
    }

    public function payload(Protocol $protocol, CarbonImmutable $now): array
    {
        $timing = $this->timing($protocol, $now);
        $record = DB::table('protocol_evaluations')->where('protocol_id', $protocol->id)->first();
        $base = (int) (PlusPage::current()->protocol_price_cents ?? 14700);
        $discount = $timing['discountEligible'] ? 10 : 0;
        $questions = $this->questions($protocol);
        return $timing + ['protocolId' => $protocol->id, 'horseId' => $protocol->horse_id,
            'horseName' => $protocol->horse->name, 'questions' => $questions, 'questionVersion' => $this->questionVersion($questions),
            'answers' => $record ? json_decode($record->answers, true) : (object) [],
            'submittedAt' => $record?->submitted_at,
            'basePriceCents' => $base,
            'priceCents' => (int) round($base * (100 - $discount) / 100),
            'discountPercent' => $discount,
            'durationMonths' => 3];
    }

    public function admin(Protocol $protocol): ?array
    {
        $record = DB::table('protocol_evaluations')->where('protocol_id', $protocol->id)->whereNotNull('submitted_at')->first();
        return $record ? [...(array) $record, 'questions' => json_decode($record->questions, true), 'answers' => json_decode($record->answers, true)] : null;
    }
}
