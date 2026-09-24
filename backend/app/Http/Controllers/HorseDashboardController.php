<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use App\Models\User;
use App\Support\HorseDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HorseDashboardController extends Controller
{
    public function show(Request $request, Horse $horse, HorseDashboard $dashboard)
    {
        $user = $this->owner($request, $horse);
        $data = $request->validate(['timezone' => ['sometimes', 'timezone'], 'month' => ['sometimes', 'date_format:Y-m']]);

        return response()->json($dashboard->build($user, $horse, CarbonImmutable::now($data['timezone'] ?? config('app.timezone')), $data['month'] ?? null));
    }

    public function weeklyUpdate(Request $request, Horse $horse, HorseDashboard $dashboard)
    {
        $user = $this->owner($request, $horse);
        $data = $request->validate(['protocol_id' => ['required', 'uuid'], 'note' => ['required', 'string', 'max:10000'], 'mood' => ['nullable', 'integer', 'between:1,5'], 'timezone' => ['sometimes', 'timezone']]);
        $now = CarbonImmutable::now($data['timezone'] ?? config('app.timezone'));
        $protocol = $horse->protocols()->whereKey($data['protocol_id'])->where('status', 'active')->whereNotNull('published_at')->where('published_at', '<=', $now)->firstOrFail();
        $presentation = $dashboard->protocol($protocol, $now);
        abort_unless(collect($presentation['notifications'])->contains('type', 'weekly_update'), 422, 'Er staat geen weekupdate open.');
        DB::table('protocol_weekly_updates')->updateOrInsert(['protocol_id' => $protocol->id, 'week_number' => $presentation['currentWeek']], [
            'user_id' => $user->id, 'note' => $data['note'], 'mood' => $data['mood'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['saved' => true]);
    }

    public function day(Request $request, Horse $horse, \App\Support\ProtocolDayHistory $history)
    {
        $this->owner($request, $horse);
        $data = $request->validate([
            'protocol_id' => ['required', 'uuid'], 'date' => ['required', 'date_format:Y-m-d'],
            'timezone' => ['sometimes', 'timezone'],
            'item_id' => [$request->isMethod('POST') ? 'required' : 'sometimes', 'uuid'],
            'done' => [$request->isMethod('POST') ? 'required' : 'sometimes', 'boolean'],
        ]);
        $now = CarbonImmutable::now($data['timezone'] ?? 'Europe/Amsterdam');
        $protocol = $horse->protocols()->whereKey($data['protocol_id'])->where('status', 'active')
            ->whereNotNull('published_at')->where('published_at', '<=', $now)->firstOrFail();

        return response()->json($request->isMethod('POST')
            ? $history->setDone($protocol, $data['date'], $data['item_id'], (bool) $data['done'], $now)
            : $history->day($protocol, $data['date'], $now));
    }

    private function owner(Request $request, Horse $horse): User
    {
        $user = User::query()->whereNull('disabled_at')->findOrFail($request->attributes->get('powersync_user_id'));
        abort_unless($horse->owner_id === $user->id && $horse->status === 'active', 404);

        return $user;
    }
}
