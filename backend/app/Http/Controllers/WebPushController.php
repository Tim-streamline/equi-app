<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Models\WebPushSubscription;
use Illuminate\Http\Request;

class WebPushController extends Controller
{
    public function config()
    {
        return response()->json(['publicKey' => config('webpush.private_key') && config('webpush.subject') ? config('webpush.public_key') : null]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['timezone' => ['sometimes', 'timezone'], 'endpoint' => ['required', 'url:https', 'max:2048'], 'keys.p256dh' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{87}=?$/'], 'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{22}={0,2}$/']]);
        // Browser-owned services only: a submitted endpoint must never target an arbitrary server.
        $url = parse_url($data['endpoint']);
        $host = strtolower($url['host'] ?? '');
        abort_unless(! isset($url['user']) && ! isset($url['pass']) && ! isset($url['fragment']) && ($url['port'] ?? 443) === 443
            && (in_array($host, ['fcm.googleapis.com', 'web.push.apple.com', 'updates.push.services.mozilla.com'], true)
                || str_ends_with($host, '.push.services.mozilla.com') || str_ends_with($host, '.notify.windows.com')), 422, 'Onbekende pushdienst.');
        $preferences = NotificationPreference::firstOrCreate(['user_id' => $request->attributes->get('powersync_user_id')]);
        if (isset($data['timezone'])) {
            $preferences->update(['timezone' => $data['timezone']]);
        }
        unset($data['timezone']);
        WebPushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])],
            ['user_id' => $request->attributes->get('powersync_user_id'), 'subscription' => $data]);

        return response()->json(['saved' => true]);
    }

    public function remove(Request $request)
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);
        WebPushSubscription::whereKey(hash('sha256', $data['endpoint']))->where('user_id', $request->attributes->get('powersync_user_id'))->delete();

        return response()->json(['removed' => true]);
    }
}
