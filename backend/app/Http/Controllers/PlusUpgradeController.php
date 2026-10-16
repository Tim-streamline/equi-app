<?php

namespace App\Http\Controllers;

use App\Support\ProtocolAccess;
use App\Support\ProtocolPurchases;
use Illuminate\Http\Request;

class PlusUpgradeController extends Controller
{
    public function create(Request $request, ProtocolPurchases $purchases)
    {
        $data = $request->validate(['horse_id' => ['required', 'uuid']]);
        $protocol = $purchases->createWithoutPayment($request->attributes->get('powersync_user_id'), $data['horse_id']);

        return response()->json(['protocolId' => $protocol->id, 'bookingId' => $protocol->intake->id]);
    }

    public function state(Request $request, ProtocolAccess $access)
    {
        $data = $request->validate(['horse_id' => ['required', 'uuid']]);

        return response()->json($access->state($request->attributes->get('powersync_user_id'), $data['horse_id']));
    }

    public function start(Request $request, ProtocolAccess $access)
    {
        $data = $request->validate(['horse_id' => ['required', 'uuid']]);
        $booking = $access->start($request->attributes->get('powersync_user_id'), $data['horse_id']);

        return response()->json(['bookingId' => $booking->id]);
    }
}
