<?php

namespace App\Support\Shelby;

/**
 * A fast safety net before the AI answers. A hit makes Shelby lead with the vet,
 * and it is the answer of last resort when the AI cannot be reached.
 */
class ShelbyTriage
{
    private const SIGNALS = [
        'koliek', 'rollen', 'rolt', 'naar zijn buik', 'naar haar buik', 'naar de buik', 'kijkt naar', 'flank',
        'kreupel', 'mank', 'hoefbevang', 'bevangen', 'koorts', 'temperatuur van 39', 'temperatuur van 40',
        'benauwd', 'ademnood', 'hijgt', 'zwaar ademen', 'bloedt', 'bloeding', 'diepe wond', 'open wond', 'ernstige wond',
        'gebroken', 'breuk', 'kan niet staan', 'ligt plat en', 'wil niet opstaan', 'staat niet op', 'stuipen', 'trillen',
        'vergiftig', 'giftig gegeten', 'acuut', 'spoed', 'verslechtert snel', 'snel achteruit', 'zweet en', 'slokdarmverstopping',
    ];

    public function acute(string $text): bool
    {
        $text = mb_strtolower($text);
        foreach (self::SIGNALS as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    /** Fixed answer when an acute message arrives while the AI is unavailable. */
    public function fallback(): string
    {
        return 'Dit klinkt alsof er iets acuuts kan spelen. Bel nu je dierenarts en wacht niet af. Als je paard weer stabiel is, kijken we samen waarom het systeem zo vastloopt.';
    }
}
