<?php

namespace App\Enums;

enum SqlPolicyRuleEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
