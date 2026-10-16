<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\IntakeBooking;
use Illuminate\Support\Str;

/** Access to existing purchased or legacy intakes never grants another protocol. */
class ProtocolAccess
{
    public function existingForHorse(string $userId, string $horseId): ?IntakeBooking
    {
        $this->owner($userId, $horseId);

        return IntakeBooking::where('user_id', $userId)->where('horse_id', $horseId)->where('status', '!=', 'cancelled')
            ->whereHas('protocol', fn ($q) => $q->whereNotIn('status', ['completed', 'archived']))
            ->orderByRaw('submitted_at IS NOT NULL')->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    public function state(string $userId, string $horseId): array
    {
        $booking = $this->existingForHorse($userId, $horseId);

        return ['intakeAvailable' => (bool) $booking, 'bookingId' => $booking?->id,
            'protocolId' => $booking?->protocol_id, 'checkoutAvailable' => false];
    }

    public function start(string $userId, string $horseId): IntakeBooking
    {
        $booking = $this->existingForHorse($userId, $horseId);
        abort_unless($booking, 403, 'Koop eerst een protocol voor dit paard. De bijbehorende intake wordt na aankoop aangemaakt.');

        return $booking;
    }

    public function authorizeBooking(string $userId, ?string $horseId, string $bookingId): void
    {
        $this->owner($userId, $horseId ?? '');
        abort_unless(IntakeBooking::whereKey($bookingId)->where('user_id', $userId)->where('horse_id', $horseId)->lockForUpdate()->first(),
            403, 'Deze intake is niet beschikbaar voor dit paard of account.');
    }

    private function owner(string $userId, string $horseId): void
    {
        abort_unless(Str::isUuid($horseId) && Horse::whereKey($horseId)->where('owner_id', $userId)->where('status', 'active')->exists(),
            403, 'Selecteer je eigen paard.');
    }
}
