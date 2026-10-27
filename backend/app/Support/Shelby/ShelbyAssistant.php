<?php

namespace App\Support\Shelby;

use App\Models\Horse;
use Throwable;

/** Builds Shelby's instructions and context, asks the AI and applies the safety and EquiBoost rules. */
class ShelbyAssistant
{
    public function __construct(private ShelbyTriage $triage, private ShelbyModel $model) {}

    public function systemPrompt(): string
    {
        return trim((string) file_get_contents(resource_path('shelby/system-prompt.md')));
    }

    /**
     * @param  list<array{role: string, body: string}>  $history  Recent messages from today's chat, oldest first.
     * @param  bool|null  $plusMentioned  Whether EquiBoost was already named anywhere in today's chat.
     * @return array{body: string, acute: bool, plus_cta: bool, provider: string, model: string, ms: int, fallback: bool}
     */
    public function answer(string $question, array $history, ?Horse $horse, bool $hasPlus, ?string $provider = null, ?bool $plusMentioned = null): array
    {
        $provider ??= (string) config('shelby.provider');
        $acute = $this->triage->acute($question);
        // The caller can check the whole conversation; the history sent to the AI is only the recent part.
        $plusMentioned ??= collect($history)->contains(fn ($m) => $m['role'] === 'assistant' && $this->mentionsPlus($m['body']));
        $context = $this->context($horse, $hasPlus, $plusMentioned, $acute);
        $messages = [];
        foreach ($history as $message) {
            if (in_array($message['role'], ['user', 'assistant'], true) && trim($message['body']) !== '') {
                $messages[] = ['role' => $message['role'], 'content' => $message['body']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $question];
        $started = microtime(true);
        try {
            $body = $this->model->reply($provider, $this->systemPrompt()."\n\n".$context, $this->alternating($messages));
            $fallback = false;
        } catch (Throwable $error) {
            // A safety question must never go unanswered because the AI is down.
            if (! $acute) {
                throw $error;
            }
            $body = $this->triage->fallback();
            $fallback = true;
        }
        $body = $this->tidy($body);

        return [
            'body' => $body, 'acute' => $acute, 'fallback' => $fallback,
            // The app shows a button to EquiBoost only for the first logical mention, never with an acute signal.
            'plus_cta' => ! $acute && ! $hasPlus && ! $plusMentioned && $this->mentionsPlus($body),
            'provider' => $provider, 'model' => $this->model->model($provider),
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    public function mentionsPlus(string $text): bool
    {
        // "Plus" is the former name; older answers in today's chat still count as a mention.
        return (bool) preg_match('/\bEquiBoost\b|\bPlus\b/iu', $text);
    }

    private function context(?Horse $horse, bool $hasPlus, bool $plusMentioned, bool $acute): string
    {
        $lines = ['SITUATIE VAN DIT GESPREK'];
        if ($horse) {
            $about = array_filter([$horse->name, $horse->breed ?? null]);
            $lines[] = 'Het gesprek gaat over het paard: '.implode(', ', $about).'.';
        }
        $lines[] = $hasPlus
            ? 'Deze gebruiker heeft voor dit paard al EquiBoost. Raad EquiBoost niet aan; verwijs bij vragen over een plan naar het protocol in de app.'
            : 'Deze gebruiker heeft voor dit paard nog geen EquiBoost.';
        if ($plusMentioned) {
            $lines[] = 'Je hebt EquiBoost in dit gesprek al genoemd. Noem het niet opnieuw, tenzij de gebruiker er zelf naar vraagt.';
        }
        if ($acute) {
            $lines[] = 'LET OP: dit bericht bevat mogelijk een acuut signaal. Begin met de dierenarts, houd het kort en noem EquiBoost niet.';
        }

        return implode("\n", $lines);
    }

    /** Both providers require alternating turns that start with the owner. */
    private function alternating(array $messages): array
    {
        $result = [];
        foreach ($messages as $message) {
            if (! $result && $message['role'] !== 'user') {
                continue;
            }
            $last = array_key_last($result);
            if ($last !== null && $result[$last]['role'] === $message['role']) {
                $result[$last]['content'] .= "\n\n".$message['content'];
            } else {
                $result[] = $message;
            }
        }

        return $result;
    }

    /** House style: no dashes as punctuation and no Markdown in a chat bubble. */
    private function tidy(string $text): string
    {
        $text = preg_replace('/\s+[—–]\s+/u', ', ', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/u', '$1', $text);
        $text = preg_replace('/^#{1,6}\s+/mu', '', $text);

        return trim(preg_replace("/\n{3,}/u", "\n\n", $text));
    }
}
