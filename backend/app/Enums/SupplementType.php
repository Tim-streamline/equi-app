<?php

namespace App\Enums;

enum SupplementType: string
{
    case Herb = 'kruid';
    case Mineral = 'mineraal';
    case Supplement = 'supplement';
}
