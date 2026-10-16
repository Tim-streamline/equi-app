<?php

namespace App\Support;

use App\Models\Protocol;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Browser deliveries have independent deduplication from native Expo tickets. */
class BrowserReminders
{
    public function protocol(Protocol $protocol, CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($protocol, $now) {
            $protocol = Protocol::whereKey($protocol->id)->lockForUpdate()->first();
            if (! $protocol || $protocol->status !== 'active' || ! $protocol->published_at || $protocol->published_at->gt($now)) {
                return 0;
            }
            $owner = $protocol->horse->owner;
            if (! $owner || $owner->disabled_at || ! $owner->notifications_on || ! $owner->notificationPreferences?->reminder_protocol || $protocol->horse->status !== 'active') {
                return 0;
            }
            $latest = $protocol->horse->protocols()->where('status', 'active')->whereNotNull('published_at')->where('published_at', '<=', $now)->orderByDesc('published_at')->first();
            if ($latest?->id !== $protocol->id) {
                return 0;
            }
            $now = $now->setTimezone($owner->notificationPreferences->timezone ?: 'Europe/Amsterdam');
            $groups = [];
            foreach ($protocol->phases()->with('weeks')->get() as $phase) {
                $availability = app(ProtocolPhaseAvailability::class)->forPhase($protocol, $phase, $now);
                if ($availability['state'] === 'preview') {
                    $groups[CarbonImmutable::parse($availability['availableAt'])->toDateString()][] = $phase;
                }
            }
            $sent = 0;
            foreach ($groups as $date => $phases) {
                $events = [];
                foreach ($phases as $phase) {
                    $events['phase:'.$phase->id.':'.$date] = $phase;
                }
                $sent += $this->deliver($owner, $events, function ($pending) use ($date, $now, $owner, $protocol) {
                    $names = implode(' en ', array_map(fn ($p) => $p->title, $pending));

                    return [
                        'title' => 'Je volgende fase staat klaar 🌿',
                        'body' => ($date === $now->toDateString() ? 'Over een week ' : 'Binnenkort ').(count($pending) === 1 ? 'start ' : 'starten ').$names.'. Bekijk alvast wat er verandert en welke kruiden of supplementen je nodig hebt.',
                        'data' => ['type' => 'protocol_phase_preview', 'userId' => $owner->id, 'horseId' => $protocol->horse_id, 'protocolId' => $protocol->id, 'phaseIds' => array_values(array_map(fn ($p) => $p->id, $pending))],
                    ];
                });
            }

            return $sent;
        });
    }

    public function credits(User $user): int
    {
        return DB::transaction(function () use ($user) {
            $user = User::whereKey($user->id)->lockForUpdate()->first();
            if (! $user || $user->disabled_at || ! $user->notifications_on) {
                return 0;
            }
            $ledger = app(CreditLedger::class);
            $ledger->expire($user);
            $sent = 0;
            foreach ($ledger->grants($user)->where('source', 'purchased') as $grant) {
                if (! $grant->expires_at) {
                    continue;
                }
                $expiry = CarbonImmutable::parse($grant->expires_at);
                if ($expiry->lte(now()) || $expiry->gt(now()->addDays(30))) {
                    continue;
                }
                $days = $expiry->lte(now()->addDays(7)) ? 7 : 30;
                $date = $expiry->setTimezone($user->notificationPreferences?->timezone ?: 'Europe/Amsterdam')->locale('nl')->translatedFormat('j F');
                $sent += $this->deliver($user, ['credit:'.$grant->id.':'.$days => true], fn () => [
                    'title' => 'Je credits verlopen binnenkort',
                    'body' => $grant->remaining.' '.($grant->remaining === 1 ? 'credit verloopt' : 'credits verlopen').' op '.$date.'.',
                    'data' => ['type' => 'credit_expiry', 'userId' => $user->id],
                ]);
            }

            return $sent;
        });
    }

    private function deliver(User $user, array $events, callable $payload): int
    {
        $sender = app(BrowserPush::class);
        $sent = 0;
        foreach ($sender->destinations($user) as $destination => $subscription) {
            $keys = [];
            $pending = [];
            foreach ($events as $event => $value) {
                $key = ['user_id' => $user->id, 'event_key' => hash('sha256', $event), 'destination' => $destination];
                if (! DB::table('browser_reminder_deliveries')->where($key)->exists()) {
                    $keys[] = $key;
                    $pending[] = $value;
                }
            }
            if (! $pending) {
                continue;
            }
            try {
                if ($sender->send($user, $subscription, $payload($pending))) {
                    foreach ($keys as $key) {
                        DB::table('browser_reminder_deliveries')->insert($key + ['sent_at' => now()]);
                    }
                    $sent++;
                }
            } catch (\Throwable $error) {
                report($error);
            }
        }

        return $sent;
    }
}
