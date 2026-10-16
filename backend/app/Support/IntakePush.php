<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IntakePush
{
    public function destinations(User $user): array
    {
        $destinations = [];
        if ($token = $user->notificationPreferences?->push_token) {
            $destinations[hash('sha256', $token)] = ['type' => 'native', 'token' => $token];
        }
        foreach (app(BrowserPush::class)->destinations($user) as $key => $subscription) {
            $destinations[$key] = ['type' => 'web', 'subscription' => $subscription];
        }

        return $destinations;
    }

    public function send(User $user, array $destination, array $payload): bool
    {
        if ($destination['type'] === 'native') {
            $request = Http::timeout(20)->acceptJson();
            if ($token = config('services.expo.access_token')) {
                $request = $request->withToken($token);
            }
            $ticket = $request->post('https://exp.host/--/api/v2/push/send', ['to' => $destination['token'], 'sound' => 'default', 'channelId' => 'intake'] + $payload)->throw()->json('data');
            if (($ticket['status'] ?? '') === 'ok') {
                return true;
            }
            if (($ticket['details']['error'] ?? '') === 'DeviceNotRegistered') {
                $user->notificationPreferences()->where('push_token', $destination['token'])->update(['push_token' => null]);

                return false;
            }
            throw new RuntimeException('Intake push rejected by Expo.');
        }

        return app(BrowserPush::class)->send($user, $destination['subscription'], $payload);
    }
}
