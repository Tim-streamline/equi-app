<?php

namespace App\Support;

use App\Mail\IntakeReceived;
use App\Mail\RecoveryPlanPublished;
use App\Models\IntakeBooking;
use App\Models\Protocol;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Sends the customer emails that follow an intake submission and a recovery plan publication, at most once each.
 * Uses the same durable claim as IntakeSubmissionMail: a sending/failed row is never retried automatically,
 * because SMTP may already have accepted the message.
 */
class CustomerNotificationMail
{
    /** The customer is mailed this long after publishing, so a correction made straight after is not announced twice. */
    public const PUBLISHED_DELAY_MINUTES = 30;

    /** Transports that accept a message without delivering it to the customer. */
    private const NON_DELIVERING = ['log', 'array'];

    /**
     * A plan the customer can actually open, as in HorseDashboard, and published long enough ago.
     * The horse must be active: an archived horse is not shown in the app, so its plan is not announced.
     * Such a row stays pending (no expiry), so a horse restored soon after is announced then.
     */
    public static function publishedPlanIsDue($query)
    {
        $shown = Protocol::from('protocols as candidate')->whereColumn('candidate.horse_id', 'protocols.horse_id')
            ->shownToOwner(CarbonImmutable::now(), true)->select('candidate.id')->limit(1);

        // $shown already limits this to a visible status and a past publication.
        return $query->where('published_at', '<=', now()->subMinutes(self::PUBLISHED_DELAY_MINUTES))
            ->where('id', '=', $shown)
            ->whereHas('horse', fn ($horse) => $horse->where('status', 'active'));
    }

    /** Only pending confirmations on cancelled bookings may be retired; an in-flight delivery keeps its claim. */
    public static function skipCancelledConfirmations(): void
    {
        IntakeBooking::where('status', 'cancelled')->where('confirmation_email_status', 'pending')
            ->toBase()->update(['confirmation_email_status' => 'skipped']);
    }

    public static function intakeIsDue($query)
    {
        return $query->where('intake_status', 'submitted')->whereNotNull('submitted_at')
            ->where(fn ($booking) => $booking->where('status', '!=', 'cancelled')->orWhereNull('status'));
    }

    /**
     * Why customer mail must wait instead of being claimed, or null when it can be sent. A row that is
     * claimed is never retried, so a mailer that only logs or keeps messages in memory (directly, or as
     * any part of a failover/round-robin mailer such as smtp -> log) would mark mail as sent that never
     * reached the customer. Mail::fake() and the array mailer in the test suite are deliberate fakes.
     */
    public function deliveryHold(): ?string
    {
        if (Mail::getFacadeRoot() instanceof MailFake) {
            return null;
        }
        if (self::transipCredentialsMissing()) {
            return 'TransIP SMTP-wachtwoord of gebruikersnaam ontbreekt. Klantmails blijven in afwachting.';
        }
        $default = (string) config('mail.default');
        $fakes = $this->nonDelivering($default);
        $testArray = $default === 'array' && config('mail.mailers.array.transport') === 'array' && app()->runningUnitTests();
        if ($fakes && ! $testArray) {
            return 'Mailer "'.$default.'" bezorgt niet echt ('.implode(', ', $fakes).'). Klantmails blijven in afwachting tot een echte mailtransport is ingesteld.';
        }

        return null;
    }

    /** The TransIP mailbox is selected but not configured yet: all mail waits instead of failing. */
    public static function transipCredentialsMissing(): bool
    {
        return config('mail.default') === 'smtp' && config('mail.mailers.smtp.host') === 'smtp.transip.email'
            && (blank(config('mail.mailers.smtp.username')) || blank(config('mail.mailers.smtp.password')));
    }

    /** @return list<string> The parts of a mailer that do not deliver, including inside failover and round-robin mailers. */
    private function nonDelivering(string $mailer, array $seen = []): array
    {
        if (in_array($mailer, $seen, true)) {
            return [$mailer.' (circulaire configuratie)'];
        }
        $config = config('mail.mailers.'.$mailer);
        $transport = is_array($config) ? ($config['transport'] ?? null) : null;
        if (! $transport) {
            return [$mailer.' (niet ingesteld)'];
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            if (empty($config['mailers'])) {
                return [$mailer.' (geen transport ingesteld)'];
            }
            return array_values(array_merge(...array_map(fn ($inner) => $this->nonDelivering((string) $inner, [...$seen, $mailer]), $config['mailers'] ?? [])));
        }

        return in_array($transport, self::NON_DELIVERING, true) ? [$mailer.' ('.$transport.')'] : [];
    }

    public function sendIntakeReceived(string $bookingId): void
    {
        $this->sendOnce(IntakeBooking::class, $bookingId, 'confirmation_email',
            fn ($query) => self::intakeIsDue($query),
            function (IntakeBooking $booking) {
                $booking->load('answers', 'user');

                return $this->recipient($booking->user, fn (User $user) => new IntakeReceived(
                    $this->firstName($user), $booking->intakeHorseName()));
            });
    }

    public function sendRecoveryPlanPublished(string $protocolId): void
    {
        $this->sendOnce(Protocol::class, $protocolId, 'published_email',
            fn ($query) => self::publishedPlanIsDue($query),
            function (Protocol $protocol) {
                $protocol->load('horse.owner');

                return $this->recipient($protocol->horse?->owner, fn (User $user) => new RecoveryPlanPublished(
                    $this->firstName($user), $protocol->horse->name, rtrim(config('app.web_url'), '/').'/',
                    app(SubscriptionAccess::class)->active($user->id) !== null));
            },
            function (Protocol $announced) {
                // A delivered newer publication supersedes older pending notices. A later follow-up
                // stays pending while the current plan shows; an unpublished correction retires nothing.
                Protocol::where('horse_id', $announced->horse_id)->where('published_email_status', 'pending')
                    ->whereIn('status', ['paused', 'completed'])->where('published_at', '<', $announced->published_at)
                    ->toBase()->update(['published_email_status' => 'skipped']);
            });
    }

    /** @return array{0: User, 1: Mailable}|null */
    private function recipient(?User $user, callable $mail): ?array
    {
        return $user && filled($user->email) && ! $user->disabled_at ? [$user, $mail($user)] : null;
    }

    private function firstName(User $user): string
    {
        return Str::before(trim($user->name ?? ''), ' ') ?: 'daar';
    }

    /** @param  class-string<Model>  $model */
    private function sendOnce(string $model, string $id, string $prefix, callable $scope, callable $build, ?callable $afterDelivery = null): void
    {
        // Hold before claiming: a claimed row is final, so it must only be claimed for a real delivery.
        if ($this->deliveryHold() !== null) {
            return;
        }
        // Base-query updates: delivery bookkeeping must not touch updated_at (a synced, customer-visible column).
        $claimed = $scope($model::whereKey($id)->where($prefix.'_status', 'pending'))->toBase()
            ->update([$prefix.'_status' => 'sending', $prefix.'_claimed_at' => now()]);
        if (! $claimed) {
            return;
        }

        try {
            $record = $model::findOrFail($id);
            $message = $build($record);
            if (! $message) {
                // The recipient or announcement is no longer eligible.
                $model::whereKey($id)->toBase()->update([$prefix.'_status' => 'skipped']);

                return;
            }
            [$user, $mailable] = $message;
            // A real transport confirms with a SentMessage; Mail::fake() records the mail and returns nothing.
            if (! Mail::to($user->email, $user->name)->send($mailable) && ! (Mail::getFacadeRoot() instanceof MailFake)) {
                throw new RuntimeException('De mailtransport heeft de verzending niet bevestigd.');
            }
            DB::transaction(function () use ($model, $id, $prefix, $afterDelivery, $record) {
                $model::whereKey($id)->toBase()->update([$prefix.'_status' => 'sent', $prefix.'_sent_at' => now(), $prefix.'_error' => null]);
                if ($afterDelivery) {
                    $afterDelivery($record);
                }
            });
        } catch (Throwable $error) {
            $model::whereKey($id)->toBase()->update([$prefix.'_status' => 'failed', $prefix.'_error' => Str::limit($error->getMessage(), 1000)]);
            throw $error;
        }
    }
}
