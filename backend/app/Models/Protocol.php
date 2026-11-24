<?php

namespace App\Models;

use App\Support\IntakePhotoPreview;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'customer_settings',
    'horse_id',
    'protocol_template_id',
    'protocol_template_name',
    'therapist_id',
    'title',
    'subtitle_analyse',
    'subtitle_protocol',
    'subtitle_calendar',
    'total_weeks',
    'current_week',
    'started_at',
    'status',
    'archived_previous_status',
    'published_at',
])]
class Protocol extends Model
{
    use HasUuids;

    /** Internal creation path used while an intake is creating its draft protocol. */
    public bool $creatingForIntake = false;

    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            $new = ! $this->exists;
            if ($new || $this->isDirty(['status', 'published_at', 'horse_id'])) \App\Support\LibrarySyncLock::acquire();
            if (! $new && $this->isDirty('horse_id')) {
                throw ValidationException::withMessages(['horse_id' => 'Een protocol en zijn intake blijven bij hetzelfde paard.']);
            }
            // Serialize competing activations, including restore and direct model writes.
            Horse::whereKey($this->horse_id)->lockForUpdate()->firstOrFail();
            if (($this->status ?? 'active') === 'active' && static::where('horse_id', $this->horse_id)
                ->where('status', 'active')->when($this->getKey(), fn ($q, $id) => $q->whereKeyNot($id))->exists()) {
                throw ValidationException::withMessages(['status' => 'Dit paard heeft al een actief protocol. Rond dat eerst af of pauzeer het.']);
            }
            // Only an unpublished-to-published transition completes intake review; later saves of a
            // published protocol (title edits, timing, repeated editor saves) must keep a reopened review.
            $publishing = $this->published_at !== null && ($new || $this->getRawOriginal('published_at') === null);
            if ($publishing && $this->published_email_status === null) {
                // The customer hears once that the plan is ready; unpublishing and republishing does not mail again.
                $this->published_email_status = 'pending';
            }
            // One place for every status transition: completing records the last plan day, reopening clears it.
            app(\App\Support\ProtocolCompletion::class)->apply($this);
            $saved = parent::save($options);
            // A new protocol gets its own intake, unless it was given the intake of another protocol to use
            // (a copy, see ProtocolCopier) or an intake is creating it (IntakeBooking::save links the two).
            if ($saved && $new && ! $this->creatingForIntake && ! $this->intake_booking_id) {
                $intake = IntakeBooking::create(['protocol_id' => $this->id, 'horse_id' => $this->horse_id,
                    'user_id' => $this->horse->owner_id, 'status' => 'pending', 'intake_status' => 'draft']);
                $this->forceFill(['intake_booking_id' => $intake->id]);
                parent::save();
            }
            if ($saved && $publishing) {
                // Publishing finishes intake review, independently of the trajectory's status. Not for a copy on
                // another horse (a test user's): that intake is a customer's, and its review is not about this copy.
                $this->intake()->where('horse_id', $this->horse_id)->whereNotNull('submitted_at')->whereIn('status', ['pending', 'confirmed'])
                    ->update(['status' => 'done', 'updated_at' => now()]);
            }

            return $saved;
        });
    }

    /** Preparing a protocol requires its own submitted intake; answer rules run at submission. */
    public function assertIntakeSubmitted(): void
    {
        if (! $this->exists || ! $this->intake()->whereNotNull('submitted_at')->where('status', '!=', 'cancelled')->exists()) {
            throw ValidationException::withMessages(['intake' => 'De bijbehorende intake moet eerst worden verstuurd voordat je het protocol kunt opstellen.']);
        }
    }

    public function delete()
    {
        return DB::transaction(function () {
            \App\Support\LibrarySyncLock::acquire();
            static::whereKey($this->id)->lockForUpdate()->firstOrFail();
            // Only the intake that was created for this protocol goes with it. A copy leaves the intake it uses alone,
            // and an intake that other protocols use keeps its protocol until those are gone.
            $own = IntakeBooking::where('protocol_id', $this->id)->value('id');
            if ($own && static::where('intake_booking_id', $own)->whereKeyNot($this->id)->exists()) {
                throw ValidationException::withMessages(['protocol' => self::intakeInUseMessage($this->title)]);
            }
            $paths = $own ? IntakeAttachment::where('booking_id', $own)->pluck('path')->all() : [];
            $paths = array_merge($paths, array_map(IntakePhotoPreview::path(...), $paths));
            $deleted = parent::delete();
            if ($deleted && $paths) {
                DB::afterCommit(function () use ($paths) {
                    try {
                        if (! Storage::disk('local')->delete($paths)) {
                            throw new \RuntimeException('Could not clean up protocol intake attachments.');
                        }
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                });
            }

            return $deleted;
        });
    }

    public static function intakeInUseMessage(string $title): string
    {
        return "De intake van protocol “{$title}” wordt ook gebruikt door andere protocollen. Selecteer die ook, of kies “Protocol verwijderen”: dan blijft de intake bewaard.";
    }

    /**
     * Deletes this protocol but keeps the intake that was created for it, with its answers and files. The intake
     * moves to another protocol of the same horse that uses it (the oldest one that is still in use, else the
     * oldest) or, when there is none, to a new empty draft like the one a purchase starts with: an intake always
     * belongs to a protocol of its horse. A copy, which only uses another protocol's intake, is simply deleted.
     *
     * @return static|null The protocol the intake now belongs to; null when this protocol did not own its intake.
     */
    public function deleteKeepingIntake(): ?self
    {
        return DB::transaction(function () {
            \App\Support\LibrarySyncLock::acquire();
            static::whereKey($this->id)->lockForUpdate()->firstOrFail();
            $own = IntakeBooking::where('protocol_id', $this->id)->lockForUpdate()->first();
            $heir = null;
            if ($own) {
                // A protocol that is still in use before a completed or archived one: the customer's intake card
                // follows the protocol the intake belongs to.
                $heir = static::where('intake_booking_id', $own->id)->where('horse_id', $own->horse_id)->whereKeyNot($this->id)
                    ->orderByRaw("status IN ('completed', 'archived')")->oldest()->orderBy('id')->lockForUpdate()->first();
                if (! $heir) {
                    // A cancelled intake is history: its placeholder must not look like an unfinished purchase.
                    $heir = new static(['horse_id' => $own->horse_id, 'title' => 'Persoonlijk protocol']
                        + ($own->status === 'cancelled' ? ['status' => 'archived', 'archived_previous_status' => 'draft'] : ['status' => 'draft']));
                    $heir->forceFill(['intake_booking_id' => $own->id])->save();
                }
                // Not through the model, which never lets an intake change protocol in normal use.
                IntakeBooking::whereKey($own->id)->update(['protocol_id' => $heir->id]);
                // The purchase that paid for this intake stays with it.
                if (! DB::table('protocol_purchases')->where('protocol_id', $heir->id)->exists()) {
                    DB::table('protocol_purchases')->where('protocol_id', $this->id)->update(['protocol_id' => $heir->id]);
                }
            }
            parent::delete();

            return $heir;
        });
    }

    /** The intake this protocol uses: its own, or for a copy the intake of the protocol it was copied from. */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(IntakeBooking::class, 'intake_booking_id');
    }

    protected function casts(): array
    {
        return [
            'customer_settings' => 'array',
            'ordered_product_keys' => 'array',
            'started_at' => 'date',
            'completed_on' => 'date',
            'closed_periods' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * The plan each horse shows, best first: the active one, else the paused one, else the
     * latest completed one. Home's cards and the Protocol tab must agree on this.
     */
    public static function shownForHorses(array $horseIds, CarbonImmutable $now, bool $inactivePlans): Builder
    {
        return static::whereIn('horse_id', $horseIds)->shownToOwner($now, $inactivePlans);
    }

    public function scopeShownToOwner(Builder $query, CarbonImmutable $now, bool $inactivePlans): Builder
    {
        return $query->visibleToOwner($now, $inactivePlans)
            // The id settles equal publication times, so Home's paused-plan link (protocol-queries.ts) can tell
            // exactly which plan the Protocol tab shows.
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 WHEN status = 'paused' THEN 1 ELSE 2 END")->orderByDesc('published_at')->orderBy('id');
    }

    /** Weeks the plan runs: its stated length, or longer when a phase schedules later weeks. */
    public function scheduledWeeks(): int
    {
        return max((int) $this->total_weeks, (int) $this->phases->flatMap->weeks->max('protocol_week_number'));
    }

    /** Plans the owner may see: published by now; paused and completed ones only when $inactivePlans. */
    public function scopeVisibleToOwner(Builder $query, \DateTimeInterface $now, bool $inactivePlans): Builder
    {
        return $query->whereIn('status', $inactivePlans ? ['active', 'paused', 'completed'] : ['active'])
            ->whereNotNull('published_at')->where('published_at', '<=', $now);
    }

    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class);
    }

    public function protocolTemplate(): BelongsTo
    {
        return $this->belongsTo(ProtocolTemplate::class);
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class);
    }

    public function phases(): HasMany
    {
        return $this->hasMany(ProtocolPhase::class)->orderBy('order');
    }

    public function currentPhase(): HasOne
    {
        return $this->hasOne(ProtocolPhase::class)
            ->where('state', 'active')
            ->orderBy('order');
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(ProtocolAnalysis::class);
    }

    public function voedingAdviezen(): HasMany
    {
        return $this->hasMany(ProtocolVoedingAdvies::class)->orderBy('title');
    }

    public function managementAdviezen(): HasMany
    {
        return $this->hasMany(ProtocolManagementAdvies::class)->orderBy('title');
    }

    public function bewegingAdviezen(): HasMany
    {
        return $this->hasMany(ProtocolBewegingAdvies::class)->orderBy('title');
    }

    /** Advice written for this protocol in the editor, per category in the editor's order. */
    public function manualAdviezen(): HasMany
    {
        return $this->hasMany(ProtocolManualAdvies::class)->orderBy('category')->orderBy('sort_order');
    }
}
