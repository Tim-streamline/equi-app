<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Horse;
use App\Support\ProtocolAccess;
use App\Support\Shelby\ShelbyAssistant;
use App\Support\SubscriptionAccess;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ShelbyChatController extends Controller
{
    public function store(Request $request, ShelbyAssistant $shelby): JsonResponse
    {
        $userId = (string) $request->attributes->get('powersync_user_id');
        $data = $request->validate([
            'horse_id' => ['required', 'uuid'],
            'client_id' => ['required', 'uuid'],
            'body' => ['required', 'string', 'max:2000'],
        ]);
        $horse = Horse::where('owner_id', $userId)->findOrFail($data['horse_id']);
        abort_unless(app(SubscriptionAccess::class)->active($userId), 403, 'Shelby is beschikbaar met een EquiApp-abonnement.');

        // One question per owner at a time: a retry that overlaps the original waits for it
        // instead of asking the AI twice, and the daily limit and message order stay exact.
        try {
            return Cache::lock('shelby-chat:'.$userId, (int) config('shelby.timeout') + 30)
                ->block((int) config('shelby.busy_wait'), fn () => $this->answer($shelby, $userId, $horse, $data));
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Shelby is nog bezig met je vorige vraag. Probeer het zo opnieuw.'], 429);
        }
    }

    private function answer(ShelbyAssistant $shelby, string $userId, Horse $horse, array $data): JsonResponse
    {
        // A retried request returns the answer that was already given.
        if ($existing = ChatMessage::whereKey($data['client_id'])->first()) {
            $session = ChatSession::where('user_id', $userId)->findOrFail($existing->session_id);

            return $this->response($session, [$existing, ChatMessage::where('session_id', $session->id)->where('order', $existing->order + 1)->first()]);
        }

        $todayStart = now(config('shelby.timezone'))->startOfDay()->utc();
        $asked = ChatMessage::where('role', 'user')->where('created_at', '>=', $todayStart)
            ->whereHas('session', fn ($q) => $q->where('user_id', $userId))->count();
        if ($asked >= config('shelby.daily_limit')) {
            return response()->json(['message' => 'Je hebt vandaag al veel vragen gesteld. Morgen denk ik graag weer met je mee.'], 429);
        }

        // Each calendar day starts a new chat per horse; older chats are never sent along as memory.
        $session = ChatSession::where('user_id', $userId)->where('horse_id', $horse->id)
            ->where('started_at', '>=', $todayStart)->orderByDesc('started_at')->first();
        $history = $session
            ? $session->messages()->reorder()->orderByDesc('order')->limit(config('shelby.history_messages'))->get(['role', 'body'])
                ->reverse()->map(fn ($m) => ['role' => $m->role, 'body' => $m->body])->values()->all()
            : [];
        $hasPlus = app(ProtocolAccess::class)->existingForHorse($userId, $horse->id) !== null
            || $horse->protocols()->whereNotIn('status', ['completed', 'archived'])->exists();

        // EquiBoost is offered at most once per chat, also in a chat longer than the history sent to the AI.
        $plusMentioned = $session && $session->messages()->where('role', 'assistant')->get(['body', 'meta'])
            ->contains(fn ($m) => ($m->meta['plus_cta'] ?? false) || $shelby->mentionsPlus($m->body));

        try {
            $answer = $shelby->answer($data['body'], $history, $horse, $hasPlus, null, $plusMentioned);
        } catch (Throwable) {
            return response()->json(['message' => 'Shelby is even niet bereikbaar. Probeer het zo opnieuw.'], 503);
        }

        [$session, $messages] = DB::transaction(function () use ($session, $userId, $horse, $data, $answer) {
            $session ??= ChatSession::create(['user_id' => $userId, 'horse_id' => $horse->id, 'started_at' => now()]);
            ChatSession::whereKey($session->id)->lockForUpdate()->first();
            $order = (int) ChatMessage::where('session_id', $session->id)->max('order') + 1;
            $question = new ChatMessage(['session_id' => $session->id, 'role' => 'user', 'body' => $data['body'], 'order' => $order]);
            $question->id = $data['client_id'];
            $question->save();
            $reply = ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'body' => $answer['body'], 'order' => $order + 1,
                'meta' => array_intersect_key($answer, array_flip(['provider', 'model', 'acute', 'fallback', 'plus_cta', 'ms']))]);

            return [$session, [$question, $reply]];
        });

        return $this->response($session, $messages);
    }

    private function response(ChatSession $session, array $messages): JsonResponse
    {
        return response()->json([
            'session_id' => $session->id,
            'messages' => array_values(array_map(fn (ChatMessage $m) => [
                'id' => $m->id, 'role' => $m->role, 'body' => $m->body, 'order' => $m->order,
                'created_at' => $m->created_at?->toIso8601String(),
                // The app only needs to know whether to show the EquiBoost button under this answer.
                'plus_cta' => (bool) ($m->meta['plus_cta'] ?? false),
            ], array_filter($messages))),
        ]);
    }
}
