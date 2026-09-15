<?php

namespace App\Support;

use App\Models\Protocol;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProtocolPhaseReminders
{
    public function send(Protocol $protocol, CarbonImmutable $now): int
    {
        $timezone = $protocol->horse->owner?->notificationPreferences?->timezone ?: 'Europe/Amsterdam';
        $dates = [];
        foreach ($protocol->phases()->with('weeks')->get() as $phase) {
            $availability = app(ProtocolPhaseAvailability::class)->forPhase($protocol, $phase, $now->setTimezone($timezone));
            if ($availability['state'] === 'preview') {
                $dates[] = CarbonImmutable::parse($availability['availableAt'])->toDateString();
            }
        }
        $sent = 0;
        foreach (array_unique($dates) as $availableOn) {
            // Commit each accepted group before attempting another group.
            $sent += $this->sendGroup($protocol, $now, $availableOn);
        }

        return $sent;
    }

    private function sendGroup(Protocol $protocol, CarbonImmutable $now, string $groupDate): int
    {
        // Serialize scheduler/manual retries for the same protocol. The unique constraint
        // is a second guard against sending an already accepted availability reminder.
        return DB::transaction(function () use ($protocol, $now, $groupDate) {
            $protocol = Protocol::whereKey($protocol->id)->lockForUpdate()->first();
            if (! $protocol || $protocol->status !== 'active' || ! $protocol->published_at || $protocol->published_at->gt($now)) {
                return 0;
            }
            // Only the current publication for this horse may send reminders.
            $latest = $protocol->horse->protocols()->where('status', 'active')->whereNotNull('published_at')
                ->where('published_at', '<=', $now)->orderByDesc('published_at')->first();
            $owner = $protocol->horse->owner;
            $preferences = $owner?->notificationPreferences;
            if ($latest?->id !== $protocol->id || $protocol->horse->status !== 'active' || $owner?->disabled_at || ! $owner?->notifications_on || ! $preferences?->reminder_protocol || ! $preferences->push_token) {
                return 0;
            }
            $now = $now->setTimezone($preferences->timezone ?: 'Europe/Amsterdam');
            $groups = [];
            foreach ($protocol->phases()->with('weeks')->get() as $phase) {
                $availability = app(ProtocolPhaseAvailability::class)->forPhase($protocol, $phase, $now);
                if ($availability['state'] !== 'preview') {
                    continue;
                }
                $availableOn = CarbonImmutable::parse($availability['availableAt'])->toDateString();
                if ($availableOn !== $groupDate) {
                    continue;
                }
                if (DB::table('protocol_phase_reminders')->where('protocol_phase_id', $phase->id)->where('user_id', $owner->id)->where('available_on', $availableOn)->exists()) {
                    continue;
                }
                $groups[$availableOn][] = $phase;
            }
            $sent = 0;
            foreach ($groups as $availableOn => $phases) {
                $ids = array_map(fn ($phase) => $phase->id, $phases);
                $names = implode(' en ', array_map(fn ($phase) => $phase->title, $phases));
                $isToday = $availableOn === $now->toDateString();
                $verb = count($phases) === 1 ? 'start ' : 'starten ';
                $request = Http::timeout(20)->acceptJson();
                if ($token = config('services.expo.access_token')) {
                    $request = $request->withToken($token);
                }
                $response = $request->post('https://exp.host/--/api/v2/push/send', [
                    'to' => $preferences->push_token,
                    'title' => 'Je volgende fase staat klaar 🌿',
                    'body' => ($isToday ? 'Over een week ' : 'Binnenkort ').$verb.$names.'. Bekijk alvast wat er verandert en welke kruiden of supplementen je nodig hebt.',
                    'sound' => 'default', 'channelId' => 'protocol',
                    'data' => ['type' => 'protocol_phase_preview', 'userId' => $owner->id, 'horseId' => $protocol->horse_id, 'protocolId' => $protocol->id, 'phaseIds' => $ids],
                ])->throw();
                $ticket = $response->json('data');
                if (($ticket['status'] ?? null) !== 'ok') {
                    if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                        $preferences->update(['push_token' => null]);

                        return $sent;
                    }
                    throw new RuntimeException('Expo rejected a protocol phase reminder: '.($ticket['details']['error'] ?? 'invalid response'));
                }
                foreach ($ids as $id) {
                    DB::table('protocol_phase_reminders')->insert([
                        'protocol_phase_id' => $id, 'user_id' => $owner->id, 'available_on' => $availableOn,
                        'ticket_id' => $ticket['id'] ?? null, 'sent_at' => $now,
                    ]);
                }
                $sent++;
            }

            return $sent;
        });
    }
}
