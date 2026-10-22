<?php

namespace App\Http\Controllers;

use App\Models\IntakeBooking;
use App\Support\IntakeSubmission;
use App\Support\ProtocolAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntakeSubmissionController extends Controller
{
    public function recovery(Request $request, string $id)
    {
        $data = $request->validate(['episode_id' => ['required', 'uuid'],
            'declarations' => ['sometimes', 'array', 'max:50'],
            'declarations.*' => ['string', 'max:255']]);
        $booking = IntakeBooking::findOrFail($id);
        $userId = $request->attributes->get('powersync_user_id');
        app(ProtocolAccess::class)->authorizeBooking($userId, $booking->horse_id, $id);

        return response()->json(['safety' => app(\App\Support\LaminitisSafety::class)->release($booking, $data['episode_id'], $userId, $data['declarations'] ?? null)]);
    }

    public function store(Request $request, string $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $booking = IntakeBooking::findOrFail($id);
            app(ProtocolAccess::class)->authorizeBooking($request->attributes->get('powersync_user_id'), $booking->horse_id, $id);
            $booking = IntakeBooking::lockForUpdate()->findOrFail($id);
            $booking = app(IntakeSubmission::class)->complete($booking);

            return response()->json(['completed_at' => $booking->completed_at->toISOString()]);
        });
    }
}
