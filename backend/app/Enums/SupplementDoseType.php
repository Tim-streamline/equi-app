<?php

namespace App\Enums;

enum SupplementDoseType: string
{
    case PerKilogram = 'per_kg';
    case Per600Kilograms = 'per_600_kg';
    case Fixed = 'vast';
}
