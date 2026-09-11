<?php

namespace App\Enums;

enum NativeProxyDeviceAuthorizationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Consumed = 'consumed';
    case Expired = 'expired';
}
