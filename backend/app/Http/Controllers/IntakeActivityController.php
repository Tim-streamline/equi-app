<?php

namespace App\Http\Controllers;

use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Support\IntakeProgress;
use Illuminate\Http\Request;

class IntakeActivityController extends Controller
{
    public function update(Request $request, IntakeBooking $booking)
    {
        abort_unless($booking->user_id === $request->attributes->get('powersync_user_id') && $booking->horse?->owner_id === $booking->user_id, 403);
        $data = $request->validate(['section' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'], 'field' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/']]);
        if (! $booking->submitted_at && $booking->status !== 'cancelled') {
            $booking->forceFill(['last_activity_at' => now(), 'last_section' => $data['section'], 'last_field' => $data['field'] ?? null])->save();
        }

        return response()->json(['saved' => true]);
    }

    public function resume(Request $request, IntakeBooking $booking, IntakeProgress $progress)
    {
        abort_unless($booking->user_id === $request->attributes->get('powersync_user_id') && $booking->horse?->owner_id === $booking->user_id, 403);
        abort_if($booking->status === 'cancelled', 409);
        $state = $progress->forBooking($booking);
        $missing = $state['missing'][0] ?? null;
        $section = $booking->last_section;
        $field = $booking->last_field;
        // Resolve saved activity using the question's current presentation section.
        $moved = $field ? IntakeField::where('answer_section_key', $section)->where('key', $field)
            ->where('active', true)->whereHas('section', fn ($q) => $q->where('active', true)
            ->whereHas('questionnaire', fn ($q) => $q->where('slug', 'protocol-intake')->where('active', true)))
            ->with('section')->first() : null;
        if ($moved) {
            $section = $moved->section->key;
        }
        if (in_array($section, ['photos', '__photos'], true)) {
            $section = 'intake-fotos';
            $field = null;
        }

        return response()->json(['horseId' => $booking->horse_id, 'submitted' => (bool) $booking->submitted_at,
            'stage' => $state['stage'], 'section' => $state['stage'] === 'almost' ? $missing['section'] : $section,
            'field' => $state['stage'] === 'almost' ? $missing['field'] : $field]);
    }
}
