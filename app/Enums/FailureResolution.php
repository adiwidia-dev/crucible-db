<?php

namespace App\Enums;

enum FailureResolution: string
{
    case Replaced = 'replaced';
    case Investigated = 'investigated';
    case NotApplicable = 'not_applicable';
}
