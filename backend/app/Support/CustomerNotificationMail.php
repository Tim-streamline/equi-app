<?php

namespace App\Support;

use App\Mail\IntakeReceived;
use App\Mail\RecoveryPlanPublished;
use App\Models\IntakeBooking;
use App\Models\Protocol;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
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

    /** A plan the customer can actually open, as in HorseDashboard, and published long enough ago. */
    public static function publishedPlanIsDue($query)
    {
        return $query->whereIn('status', ['active', 'paused', 'completed'])->whereNotNull('published_at')
            ->where('published_at', '<=', now()->subMinutes(self::PUBLISHED_DELAY_MINUTES));
    }

    public function sendIntakeReceived(string $bookingId): void
    {
        $this->sendOnce(IntakeBooking::class, $bookingId, 'confirmation_email',
            fn ($query) => $query->where('intake_status', 'submitted')->whereNotNull('submitted_at'),
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
                    $this->firstName($user), $protocol->horse->name, rtrim(config('app.web_url'), '/').'/'));
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
    private function sendOnce(string $model, string $id, string $prefix, callable $scope, callable $build): void
    {
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
                // No active account to write to: nothing was sent and nothing will be retried.
                $model::whereKey($id)->toBase()->update([$prefix.'_status' => 'skipped']);

                return;
            }
            [$user, $mailable] = $message;
            if (! Mail::to($user->email, $user->name)->send($mailable)) {
                throw new RuntimeException('De mailtransport heeft de verzending niet bevestigd.');
            }
            $model::whereKey($id)->toBase()->update([$prefix.'_status' => 'sent', $prefix.'_sent_at' => now(), $prefix.'_error' => null]);
        } catch (Throwable $error) {
            $model::whereKey($id)->toBase()->update([$prefix.'_status' => 'failed', $prefix.'_error' => Str::limit($error->getMessage(), 1000)]);
            throw $error;
        }
    }
}
