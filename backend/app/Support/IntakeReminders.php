<?php

namespace App\Support;

use App\Models\IntakeBooking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class IntakeReminders
{
    public function send(IntakeBooking $booking, CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($booking, $now) {
            $booking = IntakeBooking::whereKey($booking->id)->lockForUpdate()->first();
            if (! $booking || $booking->submitted_at || $booking->intake_status === 'submitted' || $booking->status === 'cancelled') {
                return 0;
            }
            $user = $booking->user;
            if (! $user || $user->disabled_at || ! $user->notifications_on || ! $user->notificationPreferences?->reminder_protocol || $booking->horse?->status !== 'active' || $booking->horse?->owner_id !== $user->id) {
                return 0;
            }
            if (! app(SubscriptionAccess::class)->active($user->id)) {
                return 0;
            }
            $activity = $booking->last_activity_at ?? $booking->answers()->max('updated_at') ?? $booking->started_at;
            if (! $activity) {
                return 0;
            }
            $activity = CarbonImmutable::parse($activity);
            if ($activity->addDays(3)->gt($now) || $booking->last_reminder_at?->addDays(3)->gt($now)) {
                return 0;
            }
            $progress = app(IntakeProgress::class)->forBooking($booking);
            if ($progress['total'] === 0) {
                return 0;
            }
            $name = $booking->intakeHorseName();
            [$title, $body] = match ($progress['stage']) {
                'ready' => ['Je intake is klaar om te versturen 🎉', "Alles voor {$name} is ingevuld. Controleer je antwoorden nog even en verstuur je intake, dan kunnen we aan de slag."],
                'almost' => ['Je bent bijna klaar met je intake ✨', "Je hebt al bijna alles ingevuld voor {$name}. Nog een paar vragen en dan kun je je intake versturen."],
                default => ['Je intake staat nog voor je klaar 🐴', "Ga verder met de intake voor {$name} waar je gebleven was."],
            };
            $cycle = hash('sha256', $activity->toISOString().'|'.(int) floor($activity->diffInSeconds($now) / (3 * 86400)));
            $payload = ['title' => $title, 'body' => $body, 'data' => ['type' => 'intake_reminder', 'userId' => $user->id, 'horseId' => $booking->horse_id, 'bookingId' => $booking->id]];
            $sent = 0;
            $sender = app(IntakePush::class);
            foreach ($sender->destinations($user) as $key => $destination) {
                if (DB::table('intake_reminder_deliveries')->where(['booking_id' => $booking->id, 'cycle' => $cycle, 'destination' => $key])->exists()) {
                    continue;
                }
                // Catch per destination: a failed browser must not roll back another accepted delivery.
                try {
                    if ($sender->send($user, $destination, $payload)) {
                        DB::table('intake_reminder_deliveries')->insert(['booking_id' => $booking->id, 'cycle' => $cycle, 'destination' => $key, 'sent_at' => $now]);
                        $sent++;
                    }
                } catch (\Throwable $error) {
                    report($error);
                }
            }
            if ($sent) {
                $booking->forceFill(['last_reminder_at' => $now])->save();
            }

            return $sent;
        });
    }
}
