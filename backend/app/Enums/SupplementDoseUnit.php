<?php

namespace App\Enums;

enum SupplementDoseUnit: string
{
    case Gram = 'g';
    case Milliliter = 'ml';
    case Teaspoon = 'theelepel';
    case Tablespoon = 'eetlepel';
}
