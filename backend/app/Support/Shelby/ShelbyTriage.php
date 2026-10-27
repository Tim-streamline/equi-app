<?php

namespace App\Support\Shelby;

/**
 * A fast safety net before the AI answers. A hit makes Shelby lead with the vet,
 * and it is the answer of last resort when the AI cannot be reached.
 *
 * Signals are phrases in context, not loose words: "kijkt naar zijn buik" is a colic sign,
 * "kijkt naar de andere paarden" is not. A denial right before a signal ("geen koorts",
 * "niet kreupel") and history ("vorig jaar koliek") do not count.
 */
class ShelbyTriage
{
    private const POSSESSIVE = '(?:zijn|haar|de|z\'n|d\'r)';

    /** @return list<string> */
    private function signals(): array
    {
        $own = self::POSSESSIVE;

        return [
            // Colic
            '\bkoliek',
            '\b(?:blijft|steeds|telkens|constant|herhaaldelijk|vaak|veel)\s+(?:\w+\s+){0,2}rollen\b',
            '\brol(?:t|de)\s+(?:\w+\s+){0,1}(?:steeds|telkens|constant|herhaaldelijk|de hele tijd|veel|aan één stuk)\b',
            '\b(?:kijkt|kijken|keek|bijt|bijten|schopt|schoppen|trapt)\s+(?:\w+\s+){0,2}naar\s+'.$own.'\s+(?:buik|flank|flanken)\b',
            '\b(?:geen|nauwelijks)\s+mest\s+(?:meer|sinds|vandaag)\b',
            '\b(?:mest|poept)\s+(?:al\s+)?(?:niet|nauwelijks)\s+meer\b',
            '\bslokdarmverstopping|\bschokt?\s+(?:met|bij)\s+(?:het\s+)?(?:eten|slikken)\b',
            // Lameness, laminitis, fractures
            '\bkreupel', '\bmank\b', '\bhoefbevang', '\bbevangen\b', '\bgebroken\b', '\bbotbreuk', '\bbeenbreuk',
            '\b(?:wil|kan|komt)\s+niet\s+(?:meer\s+)?(?:staan|opstaan|overeind)\b',
            '\bligt\s+(?:al\s+|steeds\s+|de hele tijd\s+|al uren\s+)plat\b',
            '\bstaat\s+(?:heel\s+)?(?:raar|vreemd)\s+op\s+(?:zijn|haar)\s+(?:voorbenen|benen|hoeven)\b',
            '\bverplaatst\s+(?:steeds\s+)?(?:zijn|haar)\s+gewicht\b',
            // Fever, breathing
            '\bkoorts',
            '\btemperatuur\s+(?:van\s+|is\s+|boven\s+)?(?:39|4\d)\b',
            '\bbenauwd', '\bademnood', '\b(?:moeilijk|zwaar|snel)\s+(?:aan het\s+)?ademen\b', '\bhijgt\s+(?:zwaar|veel|in rust|terwijl)',
            // Wounds and bleeding
            '\bbloed(?:t|en|ing|end)\b', '\b(?:diepe|grote|open|ernstige|gapende|vieze)\s+wond', '\bwond\s+(?:is\s+)?(?:diep|groot|open)\b',
            '\bzwelling\s+(?:wordt\s+)?(?:snel\s+)?groter\b', '\bdik(?:ke)?\s+(?:en\s+)?warm(?:e)?\s+(?:been|benen)\b',
            // Neurological, poisoning, sudden deterioration
            '\bstuip', '\bepilep', '\bvalt\s+(?:steeds\s+)?om\b', '\bwaggel',
            '\bvergiftig', '\bgiftig(?:e)?\s+(?:\w+\s+){0,2}gegeten\b', '\btaxus\b',
            '\b(?:gaat|ging)\s+(?:heel\s+)?(?:snel|hard)\s+achteruit\b', '\bverslechtert\s+snel\b',
            '\bzweet\s+(?:hevig|plotseling|ineens|zonder\s+reden)\b', '\bzweet\s+en\s+(?:is\s+)?(?:onrustig|trilt)\b',
            '\btrilt\s+(?:over\s+)?(?:zijn|haar)\s+(?:hele\s+)?(?:lijf|lichaam)\b',
            '\b(?:acuut|acute|spoed)\b',
        ];
    }

    /** Words that deny a signal when they come shortly before it. */
    private const DENIAL = '/\b(?:geen|niet|nooit|zonder|nauwelijks|nergens)\b(?:\s+\S+){0,2}\s*$/u';

    /** A clause about the past is not an acute situation. */
    private const HISTORY = '/\b(?:ooit|vroeger|jaren\s+geleden|vorig(?:e)?\s+(?:jaar|zomer|winter|lente|herfst|seizoen)|in\s+het\s+verleden|lang\s+geleden)\b/u';

    public function acute(string $text): bool
    {
        $text = mb_strtolower($text);
        foreach (preg_split('/(?<=[.!?\n])\s+/u', $text) ?: [$text] as $sentence) {
            foreach ($this->signals() as $signal) {
                if (! preg_match_all('/'.$signal.'/u', $sentence, $matches, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                foreach ($matches[0] as [$found, $offset]) {
                    // History only cancels its own clause: "vorig jaar koliek, nu kreupel" is still acute.
                    if (preg_match(self::HISTORY, $this->clause($sentence, $offset, strlen($found)))) {
                        continue;
                    }
                    // A denial in an earlier clause must not cancel this signal.
                    // Keep "en" within shared denials such as "zonder koorts en benauwdheid".
                    // A comma directly before the signal can join a negated symptom list.
                    // Keep commas followed by words (e.g. "wel" or "loopt") as clause boundaries.
                    $prefix = preg_replace('/,\s*$/u', ' ', substr($sentence, 0, $offset));
                    $before = mb_substr($this->clause($prefix, strlen($prefix), 0, splitAnd: false), -40);
                    // A signal that itself contains a denial ("kan niet opstaan") is never cancelled.
                    if (! preg_match('/\b(?:geen|niet|nauwelijks)\b/u', $found) && preg_match(self::DENIAL, $before)) {
                        continue;
                    }

                    return true;
                }
            }
        }

        return false;
    }

    /** The part of a sentence around a match, bounded by punctuation and joining words. */
    private function clause(string $sentence, int $offset, int $length, bool $splitAnd = true): string
    {
        $boundary = '/[,;:]|\b(?:'.($splitAnd ? 'en|' : '').'maar|nu|terwijl|sinds|daarom|want)\b/u';
        $start = 0;
        if (preg_match_all($boundary, substr($sentence, 0, $offset), $before, PREG_OFFSET_CAPTURE)) {
            $last = end($before[0]);
            $start = $last[1] + strlen($last[0]);
        }
        $rest = substr($sentence, $offset + $length);
        $end = preg_match($boundary, $rest, $after, PREG_OFFSET_CAPTURE) ? $offset + $length + $after[0][1] : strlen($sentence);

        return substr($sentence, $start, $end - $start);
    }

    /** Fixed answer when an acute message arrives while the AI is unavailable. */
    public function fallback(): string
    {
        return 'Dit klinkt alsof er iets acuuts kan spelen. Bel nu je dierenarts en wacht niet af. Als je paard weer stabiel is, kijken we samen waarom het systeem zo vastloopt.';
    }
}
