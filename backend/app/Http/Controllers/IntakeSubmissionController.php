<?php

namespace App\Http\Controllers;

use App\Models\IntakeBooking;
use App\Support\IntakeSubmission;
use App\Support\ProtocolAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntakeSubmissionController extends Controller
{
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
