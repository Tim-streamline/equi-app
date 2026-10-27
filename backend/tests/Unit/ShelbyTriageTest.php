<?php

namespace Tests\Unit;

use App\Support\Shelby\ShelbyTriage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShelbyTriageTest extends TestCase
{
    public static function acute(): array
    {
        return array_map(fn ($text) => [$text], [
            'Mijn paard rolt steeds en kijkt naar zijn buik.',
            'Ze blijft maar rollen en wil niet eten.',
            'Hij bijt naar zijn flank en is onrustig.',
            'Ik denk dat hij koliek heeft.',
            'Hij heeft sinds vanochtend geen mest meer gemaakt.',
            'Hij is ineens kreupel op links voor.',
            'Ze loopt mank sinds gisteren.',
            'Ik ben bang dat ze hoefbevangen is.',
            'Hij heeft koorts, 39,5.',
            'De temperatuur is 40 graden.',
            'Hij is benauwd en hijgt zwaar in rust.',
            'Er zit een diepe wond aan haar been en het bloedt.',
            'Ze kan niet meer opstaan.',
            'Hij ligt al uren plat.',
            'Ze staat raar op haar voorbenen en verplaatst steeds haar gewicht.',
            'Hij heeft taxus gegeten.',
            'Ze gaat heel snel achteruit.',
            'Hij zweet hevig zonder reden.',
            'Hij is niet kreupel meer, maar heeft nu wel koorts.',
            'Geen koorts, maar wel kreupel.',
            'Is dit acuut?',
        ]);
    }

    public static function notAcute(): array
    {
        return array_map(fn ($text) => [$text], [
            'Hij kijkt naar de andere paarden in de wei.',
            'Ze gaat graag rollen in het zand.',
            'Hij heeft geen acute klachten, alleen jeuk.',
            'Hij heeft geen koorts.',
            'Ze is niet kreupel, maar schuurt wel haar manen kapot.',
            'Vorig jaar heeft hij koliek gehad. Nu heeft hij mok.',
            'Ooit was ze hoefbevangen, daarom let ik op het gras.',
            'Mijn merrie schuurt haar manen helemaal kapot.',
            'Hij heeft elke winter mok.',
            'Hij heeft een klein wondje aan zijn neus.',
            'Hoe zit het met de mest? Die is wisselend.',
            'Kijkt Shelby ook naar foto\'s?',
        ]);
    }

    #[DataProvider('acute')]
    public function test_recognizes_acute_signals_in_context(string $text): void
    {
        $this->assertTrue((new ShelbyTriage)->acute($text), $text);
    }

    #[DataProvider('notAcute')]
    public function test_ignores_loose_words_denials_and_history(string $text): void
    {
        $this->assertFalse((new ShelbyTriage)->acute($text), $text);
    }
}
