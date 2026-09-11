<?php

namespace App\Enums;

enum NativeProxyConnectionStatus: string
{
    case Reserved = 'reserved';
    case Active = 'active';
    case Closed = 'closed';
    case Revoked = 'revoked';
    case Failed = 'failed';
}
