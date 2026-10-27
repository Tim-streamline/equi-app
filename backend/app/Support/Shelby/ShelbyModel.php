<?php

namespace App\Support\Shelby;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Sends one conversation to the chosen AI and returns its plain-text answer. */
class ShelbyModel
{
    public const PROVIDERS = ['openai', 'anthropic'];

    public function configured(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true) && filled(config("shelby.{$provider}.api_key"));
    }

    public function model(string $provider): string
    {
        return (string) config("shelby.{$provider}.model");
    }

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     */
    public function reply(string $provider, string $system, array $messages): string
    {
        if (! $this->configured($provider)) {
            throw new RuntimeException('shelby_not_configured');
        }
        $text = $provider === 'anthropic' ? $this->anthropic($system, $messages) : $this->openai($system, $messages);
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('shelby_empty_reply');
        }

        return $text;
    }

    private function openai(string $system, array $messages): string
    {
        $response = Http::withToken(config('shelby.openai.api_key'))->acceptJson()
            ->connectTimeout(10)->timeout(config('shelby.timeout'))
            ->post('https://api.openai.com/v1/responses', [
                'model' => $this->model('openai'), 'store' => false,
                'max_output_tokens' => config('shelby.max_output_tokens'),
                'instructions' => $system,
                'input' => array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $messages),
            ]);
        // Never log or expose the provider body: it can contain the owner's message.
        if (! $response->successful() || ($response->json('status') ?? 'completed') !== 'completed') {
            throw new RuntimeException('shelby_unavailable');
        }
        $text = '';
        foreach ($response->json('output') ?? [] as $output) {
            foreach ($output['content'] ?? [] as $part) {
                if (($part['type'] ?? '') === 'output_text') {
                    $text .= $part['text'];
                }
            }
        }

        return $text;
    }

    private function anthropic(string $system, array $messages): string
    {
        $response = Http::withHeaders(['x-api-key' => config('shelby.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])
            ->acceptJson()->connectTimeout(10)->timeout(config('shelby.timeout'))
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model('anthropic'),
                'max_tokens' => config('shelby.max_output_tokens'),
                'system' => $system,
                'messages' => $messages,
            ]);
        if (! $response->successful()) {
            throw new RuntimeException('shelby_unavailable');
        }
        $text = '';
        foreach ($response->json('content') ?? [] as $part) {
            if (($part['type'] ?? '') === 'text') {
                $text .= $part['text'];
            }
        }

        return $text;
    }
}
