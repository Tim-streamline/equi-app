<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PushTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['nullable', 'string', 'max:255', 'regex:/^(ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_-]+\]$/'],
            'timezone' => ['required', 'timezone'],
        ]);
        $userId = User::whereNull('disabled_at')->findOrFail($request->attributes->get('powersync_user_id'))->id;
        DB::transaction(function () use ($data, $userId) {
            if (! empty($data['token'])) {
                // A shared device must stop receiving the previous account's reminders.
                NotificationPreference::where('push_token', $data['token'])->where('user_id', '!=', $userId)->update(['push_token' => null]);
            }
            NotificationPreference::updateOrCreate(['user_id' => $userId], ['push_token' => $data['token'] ?? null, 'timezone' => $data['timezone']]);
        });

        return response()->json(['saved' => true]);
    }
}
