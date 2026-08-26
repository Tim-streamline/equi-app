<?php

namespace App\Enums;

enum ProtocolAdviceLayout: string
{
    case Normal = 'normal';
    case LinkToLibrary = 'link_to_library';
    case Roughage = 'roughage';
    case SupplementaryFeed = 'supplementary_feed';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::LinkToLibrary => 'Link to Library',
            self::Roughage => 'Ruwvoer',
            self::SupplementaryFeed => 'Bijvoeding',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(bool $nutrition): array
    {
        $layouts = $nutrition ? self::cases() : [self::Normal];

        return array_map(
            fn (self $layout): array => ['value' => $layout->value, 'label' => $layout->label()],
            $layouts,
        );
    }
}
