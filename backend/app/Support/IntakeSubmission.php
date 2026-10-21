<?php

namespace App\Support;

use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use Illuminate\Validation\ValidationException;

/** Called with the booking row locked, in the caller's database transaction. */
class IntakeSubmission
{
    public function complete(IntakeBooking $booking): IntakeBooking
    {
        if ($booking->completed_at) {
            return $booking;
        }
        abort_if($booking->status === 'cancelled', 409, 'Deze intake is geannuleerd.');
        app(LaminitisSafety::class)->assertAllowed(\App\Models\Horse::lockForUpdate()->findOrFail($booking->horse_id));
        $booking->load('answers');
        $progress = app(IntakeProgress::class)->forBooking($booking);
        if (! IntakeQuestionnaire::where('slug', 'protocol-intake')->where('active', true)->exists() || $progress['missing']) {
            throw ValidationException::withMessages(['intake' => 'Vul eerst alle verplichte vragen in voordat je de intake verstuurt.']);
        }
        // Also check optional attachments: a reference alone is not a received file.
        $files = IntakeAttachment::where('booking_id', $booking->id)->get()->keyBy('id');
        foreach ($booking->answers as $answer) {
            $value = json_decode($answer->value ?? 'null', true);
            foreach (is_array($value) ? $value : [] as $ref) {
                if (! is_string($ref) || ! str_starts_with($ref, 'attachment:')) {
                    continue;
                }
                $file = $files->get(explode(':', $ref)[1] ?? '');
                if (! $file || $file->section_key !== $answer->section_id || $file->field_key !== $answer->field_id) {
                    throw ValidationException::withMessages(['intake' => 'Wacht tot alle foto’s en bestanden zijn geüpload voordat je de intake verstuurt.']);
                }
            }
        }
        $now = now()->startOfSecond();
        $booking->forceFill(['completed_at' => $now, 'submitted_at' => $now, 'intake_status' => 'submitted',
            'status' => 'pending', 'submission_email_status' => $booking->submission_email_status ?? 'pending'])->save();

        return $booking;
    }
}
