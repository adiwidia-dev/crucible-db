<?php

namespace App\Enums;

enum NativeProxyLeaseStatus: string
{
    case PendingCredentials = 'pending_credentials';
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
