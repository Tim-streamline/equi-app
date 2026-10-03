<?php

namespace App\Support;

final class Brand
{
    public const NAME = 'EquiApp';

    public static function normalize(string $text): string
    {
        return preg_replace('/(?<![\p{L}\p{N}_\/@:.])(?:EquiNova|Equi[\s·.-]*App)(?![\p{L}\p{N}_\/]|\.[\p{L}\p{N}])/iu', self::NAME, $text);
    }
}
