<?php

namespace App\Support;

use App\Models\PlusPage;
use App\Models\Protocol;
use App\Models\User;
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

    /** The stored draft or submission of a protocol, if any. */
    public function record(Protocol $protocol): ?object
    {
        return DB::table('protocol_evaluations')->where('protocol_id', $protocol->id)->first();
    }

    /**
     * An evaluation stays with the owner who wrote it. After the horse changes owner,
     * the new owner can neither read nor overwrite it; the therapist still sees it.
     */
    public function writtenByAnotherOwner(Protocol $protocol, ?object $record): bool
    {
        return $record !== null && $record->user_id !== $protocol->horse->owner_id;
    }

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
        $record = $this->record($protocol);
        return $this->evaluationTiming($protocol, $now, (bool) $record?->submitted_at, ! $this->writtenByAnotherOwner($protocol, $record));
    }

    private function evaluationTiming(Protocol $protocol, CarbonImmutable $now, bool $submitted, bool $ownerMatches): array
    {
        // Use one business timezone for the offer; client timezone cannot extend a discount.
        $today = $now->setTimezone('Europe/Amsterdam')->startOfDay();
        $protocol->loadMissing('phases.weeks');
        $weeks = $protocol->scheduledWeeks();
        $end = $protocol->started_at && $weeks > 0
            ? CarbonImmutable::parse($protocol->started_at->toDateString(), 'Europe/Amsterdam')->addWeeks($weeks)->subDay() : null;
        $available = $end && $protocol->published_at && $protocol->published_at->lte($now)
            && in_array($protocol->status, ['active', 'completed'], true) && $today->gte($end->subDays(14))
            && $ownerMatches;
        return ['available' => (bool) $available, 'endsOn' => $end?->toDateString(),
            // Home words its notice by these: already sent, or closed before the scheduled end.
            'submitted' => (bool) ($available && $submitted), 'completed' => $protocol->status === 'completed',
            'daysRemaining' => $end ? (int) $today->diffInDays($end, false) : null,
            'discountUntil' => $end?->addDays(7)->toDateString(),
            'discountEligible' => (bool) ($available && $today->lte($end->addDays(7)))];
    }

    /** Home shows drafts for all owned horses. The Protocol tab links submissions for the selected horse. */
    public function dashboardLinks(User $user, ?string $horseId, CarbonImmutable $now): array
    {
        $protocols = Protocol::join('protocol_evaluations as evaluation', 'evaluation.protocol_id', '=', 'protocols.id')
            ->where('evaluation.user_id', $user->id)
            ->where(function ($query) use ($horseId) {
                $query->whereNull('evaluation.submitted_at');
                if ($horseId !== null) $query->orWhere('protocols.horse_id', $horseId);
            })
            ->whereHas('horse', fn ($query) => $query->where('owner_id', $user->id)->where('status', 'active'))
            ->with('horse', 'phases.weeks')->orderByDesc('published_at')->orderBy('protocols.id')
            ->get(['protocols.*', 'evaluation.submitted_at']);

        $links = ['evaluationDrafts' => [], 'submittedEvaluations' => []];
        foreach ($protocols as $protocol) {
            $evaluation = $this->evaluationTiming($protocol, $now, (bool) $protocol->submitted_at, ownerMatches: true);
            if (! $evaluation['available']) continue;
            if ($evaluation['submitted']) {
                $links['submittedEvaluations'][] = ['id' => $protocol->id, 'title' => $protocol->title, 'endsOn' => $evaluation['endsOn']];
            } else {
                $links['evaluationDrafts'][] = ['id' => $protocol->id, 'horseId' => $protocol->horse_id,
                    'horseName' => $protocol->horse->name, 'evaluation' => $evaluation];
            }
        }
        return $links;
    }

    public function payload(Protocol $protocol, CarbonImmutable $now): array
    {
        $timing = $this->timing($protocol, $now);
        $record = $this->record($protocol);
        if ($this->writtenByAnotherOwner($protocol, $record)) {
            $record = null;
        }
        $base = (int) (PlusPage::current()->protocol_price_cents ?? 14700);
        $discount = $timing['discountEligible'] ? 10 : 0;
        $questions = $this->questions($protocol);
        return $timing + ['protocolId' => $protocol->id, 'horseId' => $protocol->horse_id,
            'horseName' => $protocol->horse->name, 'questions' => $questions, 'questionVersion' => $this->questionVersion($questions),
            // An object also when empty, so the app always gets a map.
            'revision' => (int) ($record->revision ?? 0),
            'answers' => (object) ($record ? json_decode($record->answers, true) : []),
            'submittedAt' => $record?->submitted_at,
            'basePriceCents' => $base,
            'priceCents' => (int) round($base * (100 - $discount) / 100),
            'discountPercent' => $discount,
            'durationMonths' => 3];
    }

    public function admin(Protocol $protocol): ?array
    {
        $record = DB::table('protocol_evaluations')->where('protocol_id', $protocol->id)->whereNotNull('submitted_at')->first();
        // Stored in UTC without a zone; say so, or the browser reads them as local time.
        $utc = fn (?string $time) => $time ? CarbonImmutable::parse($time, 'UTC')->toIso8601ZuluString() : null;

        return $record ? [...(array) $record, 'questions' => json_decode($record->questions, true), 'answers' => json_decode($record->answers, true),
            'submitted_at' => $utc($record->submitted_at), 'updated_at' => $utc($record->updated_at), 'created_at' => $utc($record->created_at)] : null;
    }
}
