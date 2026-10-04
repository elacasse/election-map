<?php

namespace App\Enums;

enum DistrictResultStatus: string
{
    case Elected   = 'elected';
    case Projected = 'projected';
    case Leading   = 'leading';
}
