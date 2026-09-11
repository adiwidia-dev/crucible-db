<?php

namespace App\Support;

use App\Models\NativeProxyDeviceAuthorization;

class NativeProxyDeviceAuthorizationChallenge
{
    public function __construct(
        public readonly NativeProxyDeviceAuthorization $authorization,
        public readonly string $deviceCode,
        public readonly string $userCode,
    ) {}
}
