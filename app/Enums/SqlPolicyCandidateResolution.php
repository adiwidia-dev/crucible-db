<?php

namespace App\Enums;

enum SqlPolicyCandidateResolution: string
{
    case AllowedExact = 'allowed_exact';
    case AllowedShape = 'allowed_shape';
    case Denied = 'denied';
    case Dismissed = 'dismissed';
}
