<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\Protocol;
use App\Models\ProtocolPhaseSupplementWeek;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Copies a configured protocol to a horse of a chosen owner, as an unpublished draft.
 *
 * The destination is either a new draft (Protocol::save creates its own empty intake) or one exact,
 * still unconfigured draft of the target horse, which keeps its id, intake and purchase link. Copying is a
 * staging step: it does not require the destination intake to be submitted, but editing, publishing and
 * activating the copy stay gated on that intake as usual.
 *
 * Copied: template, therapist (when not archived), title, presentation settings, phases, weeks, supplements
 * with their weeks, advice snapshots and manual advice; the analysis only on request. Advice snapshots are
 * copied as they are, also when their library item has been archived since (snapshot semantics).
 * Never copied: intake answers and attachments, recorded supplement intakes, day snapshots, weekly updates,
 * phase reminders, purchases, start and publication dates. Weight-based automatic dosages are recalculated
 * for the target horse; when its weight is unknown they are left empty instead of keeping the source dose.
 */
class ProtocolCopier
{
    /** customer_settings that describe the source horse itself: its weight and its own intake feeds. */
    public const HORSE_SPECIFIC_SETTINGS = ['weight_kg', 'use_target_weight', 'target_weight_kg', 'feed_overrides'];

    /** @return array{protocol: Protocol, missing_dosages: int, reused_draft: bool} */
    public function copy(Protocol $source, User $owner, Horse $target, ?string $draftId, bool $includeAnalysis): array
    {
        return DB::transaction(function () use ($source, $owner, $target, $draftId, $includeAnalysis) {
            // Same lock order as the protocol editor: library lock, protocol rows, then the horse row.
            LibrarySyncLock::acquire();
            $source = Protocol::query()->whereKey($source->id)->sharedLock()->firstOrFail();
            if (! $source->protocol_template_id) {
                throw ValidationException::withMessages(['protocol' => 'Dit protocol heeft nog geen template en kan niet worden gekopieerd.']);
            }
            $draft = $draftId ? Protocol::query()->whereKey($draftId)->lockForUpdate()->first() : null;
            $target = Horse::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($target->owner_id !== $owner->id || $target->status !== 'active') {
                throw ValidationException::withMessages(['horse_id' => 'Dit paard hoort niet (meer) bij de gekozen gebruiker of is gearchiveerd.']);
            }
            if ($target->id === $source->horse_id) {
                throw ValidationException::withMessages(['horse_id' => 'Kies een ander paard dan het paard van dit protocol.']);
            }
            if ($draftId) {
                $this->assertReusableDraft($draft, $target, $owner);
            }

            $source->load([
                'phases.weeks',
                'phases.supplements.weeks',
                'analysis.advice',
                'voedingAdviezen',
                'managementAdviezen',
                'bewegingAdviezen',
                'manualAdviezen.phases',
            ]);
            $therapistId = $source->therapist_id
                && Therapist::query()->whereKey($source->therapist_id)->whereNull('archived_at')->sharedLock()->exists()
                ? $source->therapist_id : null;

            $settings = Arr::except($source->customer_settings ?? [], self::HORSE_SPECIFIC_SETTINGS);
            $attributes = [
                'protocol_template_id' => $source->protocol_template_id,
                'protocol_template_name' => $source->protocol_template_name,
                'therapist_id' => $therapistId,
                'title' => $source->title,
            ];

            if ($draft) {
                // The draft's own horse-specific settings, if any, belong to the target horse and stay.
                $attributes['customer_settings'] = $settings + Arr::only($draft->customer_settings ?? [], self::HORSE_SPECIFIC_SETTINGS) ?: null;
                $before = $draft->only([...array_keys($attributes), 'status', 'published_at']);
                $draft->update($attributes);
                $copy = $draft;
                AuditLogger::updated($copy, $before, 'Protocol gekopieerd naar bestaand concept');
            } else {
                $copy = new Protocol($attributes + [
                    'horse_id' => $target->id,
                    'customer_settings' => $settings ?: null,
                    'status' => 'draft',
                ]);
                $copy->save();
                AuditLogger::created($copy, 'Protocol gekopieerd');
            }
            $copy->setRelation('horse', $target);

            $missingDosages = $this->copyStructure($source, $copy, $includeAnalysis);
            app(ProtocolTiming::class)->synchronize($copy);

            AuditLogger::log('copied', $copy, null, [
                'source_protocol_id' => $source->id,
                'source_horse_id' => $source->horse_id,
                'target_horse_id' => $target->id,
                'target_user_id' => $owner->id,
                'destination' => $draft ? 'draft' : 'new',
                'include_analysis' => $includeAnalysis,
                'missing_dosages' => $missingDosages,
            ], 'Gekopieerd van protocol '.$source->id);

            return ['protocol' => $copy, 'missing_dosages' => $missingDosages, 'reused_draft' => (bool) $draft];
        });
    }

    /** The draft chosen in the dialog must still be exactly that: never switch destinations silently. */
    private function assertReusableDraft(?Protocol $draft, Horse $target, User $owner): void
    {
        $intake = $draft?->intake()->first();
        $reusable = $draft
            && $draft->horse_id === $target->id
            && $draft->protocol_template_id === null
            && $draft->status === 'draft'
            && $draft->published_at === null
            && $intake
            && $intake->user_id === $owner->id
            && $intake->status !== 'cancelled'
            && ! $draft->phases()->exists()
            && ! $draft->analysis()->exists()
            && ! $draft->voedingAdviezen()->exists()
            && ! $draft->managementAdviezen()->exists()
            && ! $draft->bewegingAdviezen()->exists()
            && ! $draft->manualAdviezen()->exists();

        if (! $reusable) {
            throw ValidationException::withMessages(['draft_id' => 'Het gekozen concept is gewijzigd of hoort niet meer bij dit paard. Kies de bestemming opnieuw.']);
        }
    }

    /** @return int Automatic dosages that could not be calculated for the target horse. */
    private function copyStructure(Protocol $source, Protocol $copy, bool $includeAnalysis): int
    {
        $nutrition = app(ProtocolNutrition::class);
        $weight = $nutrition->actualWeight($copy, $nutrition->answers($copy));
        $phaseIds = [];
        $missing = 0;

        foreach ($source->phases as $phase) {
            $newPhase = $phase->replicate()->setRelations([]);
            $newPhase->fill(['protocol_id' => $copy->id, 'state' => 'upcoming', 'week_start' => null, 'week_end' => null, 'chip_label' => null])->save();
            $phaseIds[$phase->id] = $newPhase->id;

            $weekIds = [];
            foreach ($phase->weeks as $week) {
                $newWeek = $week->replicate()->setRelations([]);
                $newWeek->protocol_phase_id = $newPhase->id;
                $newWeek->save();
                $weekIds[$week->id] = $newWeek->id;
            }

            foreach ($phase->supplements as $supplement) {
                $newSupplement = $supplement->replicate()->setRelations([]);
                $newSupplement->protocol_phase_id = $newPhase->id;
                if ($newSupplement->dosage_mode === 'automatic') {
                    $dosage = app(ProtocolDosage::class)->calculate($newSupplement, $weight);
                    [$amount, $unit] = $dosage === null ? [null, null] : explode(' ', $dosage, 2);
                    $newSupplement->fill([
                        'dosage' => $dosage,
                        'full_dose_amount' => $amount === null ? null : (float) $amount,
                        'full_dose_unit' => $unit,
                    ]);
                    $missing += $dosage === null ? 1 : 0;
                }
                $newSupplement->save();

                foreach ($supplement->weeks as $selectionWeek) {
                    if (isset($weekIds[$selectionWeek->protocol_phase_week_id])) {
                        ProtocolPhaseSupplementWeek::create([
                            'protocol_phase_supplement_id' => $newSupplement->id,
                            'protocol_phase_week_id' => $weekIds[$selectionWeek->protocol_phase_week_id],
                        ]);
                    }
                }
            }
        }

        if ($includeAnalysis && $source->analysis) {
            $analysis = $source->analysis->replicate()->setRelations([]);
            $analysis->protocol_id = $copy->id;
            $analysis->save();
            foreach ($source->analysis->advice as $card) {
                $newCard = $card->replicate()->setRelations([]);
                $newCard->analysis_id = $analysis->id;
                $newCard->save();
            }
        }

        foreach ([$source->voedingAdviezen, $source->managementAdviezen, $source->bewegingAdviezen] as $snapshots) {
            foreach ($snapshots as $snapshot) {
                $newSnapshot = $snapshot->replicate()->setRelations([]);
                $newSnapshot->protocol_id = $copy->id;
                $newSnapshot->save();
            }
        }

        foreach ($source->manualAdviezen as $advice) {
            $newAdvice = $advice->replicate()->setRelations([]);
            $newAdvice->protocol_id = $copy->id;
            $newAdvice->save();
            $newAdvice->phases()->sync($advice->phases->map(fn ($phase) => $phaseIds[$phase->id] ?? null)->filter()->values()->all());
        }

        return $missing;
    }
}
