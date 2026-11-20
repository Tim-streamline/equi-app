<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\Protocol;
use App\Models\ProtocolPhaseSupplementWeek;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Copies a configured protocol as an unpublished draft, either to its own horse (an additional draft next to the
 * source) or to a horse of one of the team's test users. No other horse can receive a copy.
 *
 * The destination is either a new draft or one exact, still unconfigured draft of the target horse, which keeps
 * its id, its own intake and its purchase link.
 *
 * A new draft does not get an intake of its own: it uses the intake of the source protocol (see
 * Protocol::intake). Nothing of that intake is copied or changed, so the copy can be edited as soon as that intake
 * is submitted, and a test user never receives the customer's answers and photos in the app. Editing, publishing
 * and activating a copy stay gated on its intake being submitted, as usual.
 *
 * Copied: template, therapist (when not archived), title (unless the admin chose another), presentation
 * settings, phases, weeks, supplements with their weeks, advice snapshots and manual advice; the analysis only
 * on request. Advice snapshots are copied as they are, also when their library item has been archived since
 * (snapshot semantics).
 * Never copied: safety reports, recorded supplement intakes, day snapshots, weekly updates, phase reminders,
 * purchases, start and publication dates. Weight-based automatic dosages are recalculated for the destination
 * (the weight in its intake, else the horse's weight); when that weight is unknown they are left empty instead
 * of keeping the source dose.
 */
class ProtocolCopier
{
    /** customer_settings that describe the source horse itself: its weight and its own intake feeds. */
    public const HORSE_SPECIFIC_SETTINGS = ['weight_kg', 'use_target_weight', 'target_weight_kg', 'feed_overrides'];

    /** @return array{protocol: Protocol, missing_dosages: int, reused_draft: bool, intake_submitted: bool} */
    public function copy(Protocol $source, User $owner, Horse $target, ?string $draftId, bool $includeAnalysis, ?string $title = null): array
    {
        return DB::transaction(function () use ($source, $owner, $target, $draftId, $includeAnalysis, $title) {
            // Same lock order as the protocol editor: library lock, protocol rows, then the horse row.
            LibrarySyncLock::acquire();
            $source = Protocol::query()->whereKey($source->id)->sharedLock()->firstOrFail();
            if (! $source->protocol_template_id) {
                throw ValidationException::withMessages(['protocol' => 'Dit protocol heeft nog geen template en kan niet worden gekopieerd.']);
            }
            $draft = $draftId ? Protocol::query()->whereKey($draftId)->lockForUpdate()->first() : null;
            $target = Horse::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($target->owner_id !== $owner->id || $target->status !== 'active') {
                throw ValidationException::withMessages(['horse_id' => 'Dit paard is intussen van eigenaar gewisseld of gearchiveerd.']);
            }
            if ($target->id !== $source->horse_id && ! User::query()->whereKey($owner->id)->value('is_test_user')) {
                throw ValidationException::withMessages(['horse_id' => 'Een protocol kan alleen worden gekopieerd naar het eigen paard of naar een paard van een testgebruiker.']);
            }
            if ($draftId && ! ($draft && self::reusableDrafts($owner)->whereKey($draft->id)->where('horse_id', $target->id)->exists())) {
                // The draft chosen in the dialog must still be exactly that: never switch destinations silently.
                throw ValidationException::withMessages(['draft_id' => 'Het gekozen concept is gewijzigd of hoort niet meer bij dit paard. Kies de bestemming opnieuw.']);
            }

            if (! $draft) {
                $intake = IntakeBooking::query()->findOrFail($source->intake_booking_id);
                if ($intake->status === 'cancelled') {
                    throw ValidationException::withMessages(['destination' => 'De intake van dit protocol is geannuleerd, dus een kopie kan die niet gebruiken. Zet de intake eerst terug.']);
                }
                // Only the intake's own user can submit it, and only while still owning its horse.
                if (! $intake->submitted_at && Horse::query()->whereKey($intake->horse_id)->value('owner_id') !== $intake->user_id) {
                    throw ValidationException::withMessages(['destination' => 'De intake van dit protocol is niet verstuurd en kan dat ook niet meer worden: het paard is van eigenaar gewisseld. Een kopie kan die intake daarom niet gebruiken.']);
                }
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
                // The dialog asks for the title: the customer sees it in the app, also next to the original.
                'title' => $title ?? $source->title,
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
                // The copy uses the source's intake; Protocol::save then creates none for it.
                $copy->forceFill(['intake_booking_id' => $source->intake_booking_id])->save();
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
                'intake_booking_id' => $copy->intake_booking_id,
                'missing_dosages' => $missingDosages,
            ], 'Gekopieerd van protocol '.$source->id);

            return ['protocol' => $copy, 'missing_dosages' => $missingDosages, 'reused_draft' => (bool) $draft,
                'intake_submitted' => (bool) $copy->intake()->value('submitted_at')];
        });
    }

    /**
     * The drafts a copy may fill: still unconfigured, unpublished, and with an intake of this owner that is not
     * cancelled. One definition for the dialog's list and for the check under the draft's row lock.
     *
     * @return Builder<Protocol>
     */
    public static function reusableDrafts(User $owner): Builder
    {
        $query = Protocol::query()
            ->where('status', 'draft')
            ->whereNull('protocol_template_id')
            ->whereNull('published_at')
            ->whereHas('intake', fn ($intake) => $intake->where('user_id', $owner->id)->where('status', '!=', 'cancelled'));
        foreach (['phases', 'analysis', 'voedingAdviezen', 'managementAdviezen', 'bewegingAdviezen', 'manualAdviezen'] as $relation) {
            $query->whereDoesntHave($relation);
        }

        return $query;
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
