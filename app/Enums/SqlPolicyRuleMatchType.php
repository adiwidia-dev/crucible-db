<?php

namespace App\Enums;

enum SqlPolicyRuleMatchType: string
{
    case Exact = 'exact';
    case Shape = 'shape';
}
