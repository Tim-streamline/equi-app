<?php

namespace App\Support;

use App\Models\{NotificationPreference, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Http};
use RuntimeException;

class CreditExpiryReminders
{
    public function send(User $user): int
    {
        $sent = 0;
        foreach (DB::table('credit_grants')->where('user_id', $user->id)->where('source', 'purchased')
            ->where('expires_at', '>', now())->where('expires_at', '<=', now()->addDays(30))->pluck('id') as $id) {
            // Commit accepted tickets individually; a later failure cannot undo deduplication.
            $sent += DB::transaction(function () use ($user, $id) {
                $user = User::whereKey($user->id)->lockForUpdate()->first();
                if (! $user || $user->disabled_at || ! $user->notifications_on) return 0;
                $preferences = $user->notificationPreferences;
                if (! $preferences?->push_token) return 0;
                $ledger = app(CreditLedger::class);
                $ledger->expire($user);
                $grant = $ledger->grants($user)->firstWhere('id', $id);
                if (! $grant || ! $grant->expires_at) return 0;
                $expiry = CarbonImmutable::parse($grant->expires_at);
                if ($expiry->lte(now()) || $expiry->gt(now()->addDays(30))) return 0;
                // Catch up only the most relevant threshold if a scheduled run was missed.
                $days = $expiry->lte(now()->addDays(7)) ? 7 : 30;
                $key = ['grant_id' => $id, 'days' => $days];
                if (DB::table('credit_reminders')->where($key)->exists()) return 0;
                $date = $expiry->setTimezone($preferences->timezone ?: 'Europe/Amsterdam')->locale('nl')->translatedFormat('j F');
                $ticket = $this->client()->post('https://exp.host/--/api/v2/push/send', [
                    'to' => $preferences->push_token, 'title' => 'Je credits verlopen binnenkort',
                    'body' => $grant->remaining.' '.($grant->remaining === 1 ? 'credit verloopt' : 'credits verlopen').' op '.$date.'.',
                    'sound' => 'default', 'channelId' => 'credits',
                    'data' => ['type' => 'credit_expiry', 'userId' => $user->id],
                ])->throw()->json('data');
                if (($ticket['status'] ?? null) !== 'ok' || empty($ticket['id'])) {
                    if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                        $preferences->update(['push_token' => null]);
                        return 0;
                    }
                    throw new RuntimeException('Expo rejected a credit reminder: '.($ticket['details']['error'] ?? 'invalid response'));
                }
                DB::table('credit_reminders')->insert($key + ['sent_at' => now(), 'ticket_id' => $ticket['id'], 'push_token' => $preferences->push_token]);
                return 1;
            });
        }
        return $sent;
    }

    public function receipts(): void
    {
        $pending = DB::table('credit_reminders')->whereNull('receipt_checked_at')->whereNotNull('ticket_id')
            ->where('sent_at', '<=', now()->subMinutes(15))->get();
        foreach ($pending->chunk(100) as $batch) {
            $receipts = $this->client()->post('https://exp.host/--/api/v2/push/getReceipts', ['ids' => $batch->pluck('ticket_id')->all()])->throw()->json('data') ?? [];
            foreach ($batch as $reminder) {
                $receipt = $receipts[$reminder->ticket_id] ?? null;
                if (! $receipt && CarbonImmutable::parse($reminder->sent_at)->gt(now()->subDay())) continue;
                $error = $receipt ? (($receipt['status'] ?? null) === 'ok' ? null : ($receipt['details']['error'] ?? 'UnknownError')) : 'ReceiptUnavailable';
                if ($error === 'DeviceNotRegistered') {
                    $userId = DB::table('credit_grants')->where('id', $reminder->grant_id)->value('user_id');
                    NotificationPreference::where('user_id', $userId)->where('push_token', $reminder->push_token)->update(['push_token' => null]);
                }
                DB::table('credit_reminders')->where('grant_id', $reminder->grant_id)->where('days', $reminder->days)
                    ->update(['receipt_checked_at' => now(), 'receipt_error' => $error, 'push_token' => null]);
                if ($error) report(new RuntimeException('Credit reminder receipt: '.$error));
            }
        }
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::timeout(20)->acceptJson();
        return ($token = config('services.expo.access_token')) ? $client->withToken($token) : $client;
    }
}
