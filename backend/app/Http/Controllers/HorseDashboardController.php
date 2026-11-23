<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use App\Models\User;
use App\Support\ClientCapabilities;
use App\Support\HorseDashboard;
use App\Support\LegacyProtocolClient;
use App\Support\ProtocolDayHistory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HorseDashboardController extends Controller
{
    public function home(Request $request, HorseDashboard $dashboard)
    {
        $user = User::query()->whereNull('disabled_at')->findOrFail($request->attributes->get('powersync_user_id'));
        $data = $request->validate(['timezone' => ['sometimes', 'timezone']]);

        return response()->json($this->forClient($request, $dashboard->build($user, null, CarbonImmutable::now($data['timezone'] ?? config('app.timezone')), null, ClientCapabilities::readOnlyPlans($request))));
    }

    public function show(Request $request, Horse $horse, HorseDashboard $dashboard)
    {
        $user = $this->owner($request, $horse);
        $data = $request->validate(['timezone' => ['sometimes', 'timezone'], 'month' => ['sometimes', 'date_format:Y-m']]);

        return response()->json($this->forClient($request, $dashboard->build($user, $horse, CarbonImmutable::now($data['timezone'] ?? config('app.timezone')), $data['month'] ?? null, ClientCapabilities::readOnlyPlans($request))));
    }

    public function weeklyUpdate(Request $request, Horse $horse, HorseDashboard $dashboard)
    {
        $user = $this->owner($request, $horse);
        $data = $request->validate(['protocol_id' => ['required', 'uuid'], 'note' => ['required', 'string', 'max:10000'], 'mood' => ['nullable', 'integer', 'between:1,5'], 'timezone' => ['sometimes', 'timezone']]);
        $now = CarbonImmutable::now($data['timezone'] ?? config('app.timezone'));
        DB::transaction(function () use ($horse, $data, $now, $dashboard, $user) {
            // Serialize saving with admin pause/resume, including forms opened before a pause.
            \App\Support\LibrarySyncLock::acquire();
            $protocol = $horse->protocols()->whereKey($data['protocol_id'])->whereIn('status', ['active', 'paused'])
                ->whereNotNull('published_at')->where('published_at', '<=', $now)->lockForUpdate()->firstOrFail();
            abort_if($protocol->status === 'paused', 422, 'Je herstelplan is gepauzeerd. Je kunt weer een weekupdate versturen zodra je plan is hervat.');
            $presentation = $dashboard->protocol($protocol, $now);
            abort_unless(collect($presentation['notifications'])->contains('type', 'weekly_update'), 422, 'Er staat geen weekupdate open.');
            DB::table('protocol_weekly_updates')->updateOrInsert(['protocol_id' => $protocol->id, 'week_number' => $presentation['currentWeek']], [
                'user_id' => $user->id, 'note' => $data['note'], 'mood' => $data['mood'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return response()->json(['saved' => true]);
    }

    public function startProtocol(Request $request, Horse $horse, HorseDashboard $dashboard)
    {
        $user = $this->owner($request, $horse);
        $data = $request->validate(['protocol_id' => ['required', 'uuid'],
            'confirmed' => ['required', 'accepted'], 'timezone' => ['sometimes', 'timezone']]);
        $now = CarbonImmutable::now($data['timezone'] ?? 'Europe/Amsterdam');

        DB::transaction(function () use ($horse, $data, $now) {
            // Match the protocol editor's lock order and serialize competing starts.
            \App\Support\LibrarySyncLock::acquire();
            $protocol = $horse->protocols()->whereKey($data['protocol_id'])->where('status', 'active')
                ->whereNotNull('published_at')->where('published_at', '<=', $now)->lockForUpdate()->firstOrFail();
            if (! $protocol->started_at) {
                $protocol->update(['started_at' => $now->toDateString(), 'current_week' => 1]);
            }
            app(\App\Support\ProtocolTiming::class)->synchronize($protocol, $now);
        });

        return response()->json($this->forClient($request, $dashboard->build($user, $horse, $now, null, ClientCapabilities::readOnlyPlans($request))));
    }

    /** Released apps keep their original payload; see LegacyProtocolClient. */
    private function forClient(Request $request, array $dashboard): array
    {
        return ClientCapabilities::protocolDoses($request) ? $dashboard : app(LegacyProtocolClient::class)->dashboard($dashboard);
    }

    public function day(Request $request, Horse $horse, ProtocolDayHistory $history)
    {
        $this->owner($request, $horse);
        $data = $request->validate([
            'protocol_id' => ['required', 'uuid'], 'date' => ['required', 'date_format:Y-m-d'],
            'timezone' => ['sometimes', 'timezone'],
            'item_id' => [$request->isMethod('POST') ? 'required' : 'sometimes', 'uuid'],
            'done' => [$request->isMethod('POST') ? 'required' : 'sometimes', 'boolean'],
            'administration' => ['sometimes', 'integer', 'between:1,24'],
        ]);
        $now = CarbonImmutable::now($data['timezone'] ?? 'Europe/Amsterdam');
        $protocol = $horse->protocols()->whereKey($data['protocol_id'])
            ->whereIn('status', $request->isMethod('POST') ? ['active'] : ['active', 'paused', 'completed'])
            ->whereNotNull('published_at')->where('published_at', '<=', $now)->firstOrFail();

        if (! ClientCapabilities::protocolDoses($request)) {
            // A released app's single checkbox stands for every dose of that product on that day.
            return response()->json(app(LegacyProtocolClient::class)->day($request->isMethod('POST')
                ? $history->setDone($protocol, $data['date'], $data['item_id'], (bool) $data['done'], $now, true, null)
                : $history->day($protocol, $data['date'], $now)));
        }

        return response()->json($request->isMethod('POST')
            ? $history->setDone($protocol, $data['date'], $data['item_id'], (bool) $data['done'], $now, true, $data['administration'] ?? 1)
            : $history->day($protocol, $data['date'], $now));
    }

    /** Ticks products off (or back on) in the customer's order list; one key per product and phase. */
    public function orders(Request $request, Horse $horse)
    {
        $this->owner($request, $horse);
        $data = $request->validate([
            'protocol_id' => ['required', 'uuid'],
            'keys' => ['required', 'array', 'min:1', 'max:100'],
            'keys.*' => ['string', 'max:200', 'regex:/^[^:\s]+:[^:\s]+$/'],
            'ordered' => ['required', 'boolean'],
        ]);

        $keys = DB::transaction(function () use ($horse, $data) {
            $protocol = $horse->protocols()->whereKey($data['protocol_id'])->whereNotNull('published_at')
                ->where('status', '!=', 'archived')->lockForUpdate()->firstOrFail();
            $current = $protocol->ordered_product_keys ?? [];
            $next = $data['ordered'] ? array_unique([...$current, ...$data['keys']]) : array_diff($current, $data['keys']);
            abort_if(count($next) > 1000, 422, 'Te veel producten afgevinkt.');
            $next = array_values($next);
            // A plain column write: ticking a product is not a protocol edit and must not run its save rules.
            \App\Models\Protocol::whereKey($protocol->id)->toBase()->update(['ordered_product_keys' => json_encode($next)]);

            return $next;
        });

        return response()->json(['orderedKeys' => $keys]);
    }

    private function owner(Request $request, Horse $horse): User
    {
        $user = User::query()->whereNull('disabled_at')->findOrFail($request->attributes->get('powersync_user_id'));
        abort_unless($horse->owner_id === $user->id && $horse->status === 'active', 404);

        return $user;
    }
}
