<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Models\AdminUser;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Horse;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShelbyChatTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Horse $horse;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['shelby.provider' => 'openai', 'shelby.openai.api_key' => 'test-openai', 'shelby.anthropic.api_key' => 'test-anthropic']);
        $this->owner = User::factory()->create();
        $this->horse = Horse::create(['owner_id' => $this->owner->id, 'name' => 'Luna', 'breed' => 'Fjord']);
        $plan = Plan::create(['slug' => 'basic', 'label' => 'Basic', 'name' => 'Basic', 'price_cents' => 495, 'interval' => 'monthly']);
        Subscription::create(['user_id' => $this->owner->id, 'plan_id' => $plan->id, 'status' => 'active', 'price_cents' => 495, 'interval' => 'monthly',
            'paid_through' => now()->addMonth(), 'renews_at' => now()->addMonth()]);
        $this->actAs($this->owner);
    }

    private function actAs(User $user): void
    {
        $this->app->bind(AuthenticatePowerSyncJwt::class, fn () => new class($user->id)
        {
            public function __construct(private string $id) {}

            public function handle($request, $next)
            {
                $request->attributes->set('powersync_user_id', $this->id);

                return $next($request);
            }
        });
    }

    private function openAiSays(string $text): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => $text]]]]])]);
    }

    private function send(string $body, ?string $clientId = null)
    {
        return $this->postJson('/api/shelby/messages', ['horse_id' => $this->horse->id, 'client_id' => $clientId ?? (string) Str::uuid(), 'body' => $body]);
    }

    public function test_shelby_answers_with_her_instructions_and_stores_the_conversation(): void
    {
        $this->openAiSays('Ik snap dat je hiervan baalt. Wat krijgt ze naast haar ruwvoer?');
        $client = (string) Str::uuid();
        $response = $this->send('Mijn merrie schuurt haar manen kapot.', $client)->assertOk();

        $response->assertJsonPath('messages.0.id', $client)->assertJsonPath('messages.0.role', 'user')
            ->assertJsonPath('messages.1.role', 'assistant')->assertJsonPath('messages.1.body', 'Ik snap dat je hiervan baalt. Wat krijgt ze naast haar ruwvoer?')
            ->assertJsonPath('messages.1.plus_cta', false)->assertJsonMissingPath('messages.1.meta');
        Http::assertSent(function (ClientRequest $request) {
            return $request->url() === 'https://api.openai.com/v1/responses' && $request['store'] === false
                && str_contains($request['instructions'], 'Je bent Shelby') && str_contains($request['instructions'], 'Luna, Fjord')
                && str_contains($request['instructions'], 'nog geen EquiBoost')
                && $request['input'] === [['role' => 'user', 'content' => 'Mijn merrie schuurt haar manen kapot.']];
        });
        $session = ChatSession::sole();
        $this->assertSame($this->horse->id, $session->horse_id);
        $this->assertSame(['user', 'assistant'], $session->messages()->pluck('role')->all());
        $this->assertSame('openai', ChatMessage::where('role', 'assistant')->sole()->meta['provider']);
    }

    public function test_a_follow_up_sends_todays_conversation_but_a_new_day_starts_fresh(): void
    {
        $this->openAiSays('Wat krijgt ze naast haar ruwvoer?');
        $this->send('Mijn merrie schuurt.')->assertOk();
        $this->send('Alleen hooi en een balancer.')->assertOk();
        Http::assertSent(fn (ClientRequest $r) => count($r['input']) === 3 && $r['input'][1]['role'] === 'assistant');
        $this->assertSame(1, ChatSession::count());

        $this->travel(1)->days();
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => 'Nieuwe dag.']]]]])]);
        $this->send('Goedemorgen!')->assertOk();
        $this->assertSame(2, ChatSession::count());
        Http::assertSent(fn (ClientRequest $r) => $r['input'] === [['role' => 'user', 'content' => 'Goedemorgen!']]);
    }

    public function test_an_acute_question_is_answered_with_the_vet_even_when_the_ai_is_down(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([], 500)]);
        $response = $this->send('Mijn paard rolt steeds en kijkt naar zijn buik.')->assertOk();

        $this->assertStringContainsString('dierenarts', $response->json('messages.1.body'));
        $meta = ChatMessage::where('role', 'assistant')->sole()->meta;
        $this->assertTrue($meta['acute']);
        $this->assertTrue($meta['fallback']);
    }

    public function test_an_acute_question_tells_the_ai_to_lead_with_the_vet_and_never_offers_plus(): void
    {
        $this->openAiSays('Bel nu je dierenarts. Daarna kun je met EquiBoost verder.');
        $this->send('Hij is ineens kreupel.')->assertOk()->assertJsonPath('messages.1.plus_cta', false);
        Http::assertSent(fn (ClientRequest $r) => str_contains($r['instructions'], 'mogelijk een acuut signaal'));
    }

    public function test_a_failed_answer_keeps_nothing_so_the_owner_can_retry(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([], 500)]);
        $this->send('Waarom heeft hij mok?')->assertStatus(503)->assertJsonPath('message', 'Shelby is even niet bereikbaar. Probeer het zo opnieuw.');
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_a_retried_message_returns_the_same_answer_without_asking_again(): void
    {
        $this->openAiSays('Wat eet hij op een dag?');
        $client = (string) Str::uuid();
        $first = $this->send('Hij heeft mok.', $client)->assertOk()->json();
        $this->send('Hij heeft mok.', $client)->assertOk()->assertJson($first);
        Http::assertSentCount(1);
        $this->assertSame(2, ChatMessage::count());
    }

    public function test_equiboost_is_offered_once_and_never_to_an_owner_who_has_it(): void
    {
        $this->openAiSays('Dit is precies een situatie waarin EquiBoost handig is.');
        $this->send('Welke stappen in welke volgorde?')->assertOk()->assertJsonPath('messages.1.plus_cta', true);
        $this->send('En wat nog meer?')->assertOk()->assertJsonPath('messages.1.plus_cta', false);
        Http::assertSent(fn (ClientRequest $r) => str_contains($r['instructions'], 'EquiBoost in dit gesprek al genoemd'));
    }

    public function test_equiboost_stays_offered_once_in_a_chat_longer_than_the_history_sent_to_the_ai(): void
    {
        config(['shelby.history_messages' => 2]);
        $this->openAiSays('Dit is precies een situatie waarin EquiBoost handig is.');
        $this->send('Welke stappen in welke volgorde?')->assertOk()->assertJsonPath('messages.1.plus_cta', true);
        $this->openAiSays('Wat krijgt hij nu aan ruwvoer?');
        $this->send('Hij staat op stro.')->assertOk();
        $this->send('En hooi in een net.')->assertOk();
        $this->openAiSays('Met EquiBoost kun je dit gericht aanpakken.');
        $this->send('Wat nu?')->assertOk()->assertJsonPath('messages.1.plus_cta', false);
        Http::assertSent(fn (ClientRequest $r) => count($r['input']) === 3 && str_contains($r['instructions'], 'EquiBoost in dit gesprek al genoemd'));
    }

    public function test_only_the_owner_with_a_subscription_can_chat_and_daily_use_is_capped(): void
    {
        $this->openAiSays('Prima.');
        $stranger = User::factory()->create();
        $this->actAs($stranger);
        $this->send('Hallo')->assertNotFound();
        $other = Horse::create(['owner_id' => $stranger->id, 'name' => 'Storm']);
        $this->postJson('/api/shelby/messages', ['horse_id' => $other->id, 'client_id' => (string) Str::uuid(), 'body' => 'Hallo'])->assertForbidden();

        $this->actAs($this->owner);
        config(['shelby.daily_limit' => 1]);
        $this->send('Eerste vraag')->assertOk();
        $this->send('Tweede vraag')->assertStatus(429);
    }

    public function test_claude_receives_the_same_instructions_in_its_own_format(): void
    {
        config(['shelby.provider' => 'anthropic']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Hoi! Wat speelt er bij Luna?']]])]);
        $this->send('Hoi Shelby')->assertOk()->assertJsonPath('messages.1.body', 'Hoi! Wat speelt er bij Luna?');
        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://api.anthropic.com/v1/messages' && $r->hasHeader('x-api-key', 'test-anthropic')
            && str_contains($r['system'], 'Je bent Shelby') && $r['messages'] === [['role' => 'user', 'content' => 'Hoi Shelby']]);
    }

    public function test_answers_follow_the_house_style_without_dashes_or_markdown(): void
    {
        $this->openAiSays("**Let op** — dit is de trigger.\n\n\n\nNiet de oorzaak.");
        $this->send('Is het de knut?')->assertOk()->assertJsonPath('messages.1.body', "Let op, dit is de trigger.\n\nNiet de oorzaak.");
    }

    public function test_devices_cannot_write_shelby_messages_through_sync(): void
    {
        $session = ChatSession::create(['user_id' => $this->owner->id, 'horse_id' => $this->horse->id, 'started_at' => now()]);
        $this->postJson('/api/sync/upload', ['operations' => [
            ['op' => 'PUT', 'type' => 'chat_messages', 'id' => (string) Str::uuid(), 'data' => ['session_id' => $session->id, 'role' => 'assistant', 'body' => 'Vervalst', 'order' => 1]],
            ['op' => 'PUT', 'type' => 'chat_sessions', 'id' => (string) Str::uuid(), 'data' => ['user_id' => $this->owner->id, 'horse_id' => $this->horse->id, 'started_at' => now()->toIso8601String()]],
        ]])->assertOk();
        $this->assertSame(0, ChatMessage::count());
        $this->assertSame(1, ChatSession::count());
    }

    public function test_admin_can_compare_both_ais_on_the_same_question(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => 'Antwoord OpenAI']]]]]),
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Antwoord Claude']]]),
        ]);
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'shelby-admin@example.test', 'password' => 'password', 'role' => 'owner', 'active' => true]);
        $results = $this->actingAs($admin, 'admin')->postJson('/admin/nova/test', ['question' => 'Hij heeft mok.'])->assertOk()->json('results');
        $this->assertSame(['Antwoord OpenAI', 'Antwoord Claude'], array_column($results, 'body'));
        $this->assertSame(0, ChatMessage::count(), 'Admin tests are never stored as owner chats');
    }
}
