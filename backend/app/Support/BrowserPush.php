<?php

namespace App\Support;

use App\Models\User;
use App\Models\WebPushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

class BrowserPush
{
    public function destinations(User $user): array
    {
        if (! config('webpush.public_key') || ! config('webpush.private_key') || ! config('webpush.subject')) {
            return [];
        }

        return WebPushSubscription::where('user_id', $user->id)->get()->keyBy('endpoint_hash')->all();
    }

    public function send(User $user, WebPushSubscription $subscription, array $payload): bool
    {
        // Recheck ownership in case this browser signed into another account.
        $model = WebPushSubscription::whereKey($subscription->endpoint_hash)->where('user_id', $user->id)->first();
        if (! $model) {
            return false;
        }
        $sender = new WebPush(['VAPID' => ['subject' => config('webpush.subject'), 'publicKey' => config('webpush.public_key'), 'privateKey' => config('webpush.private_key')]], ['TTL' => 86400], 20, ['allow_redirects' => false]);
        $report = $sender->sendOneNotification(Subscription::create($model->subscription), json_encode($payload, JSON_THROW_ON_ERROR));
        if ($report->isSubscriptionExpired()) {
            $model->delete();

            return false;
        }
        if (! $report->isSuccess()) {
            throw new RuntimeException('Browser push service rejected reminder.');
        }

        return true;
    }
}
