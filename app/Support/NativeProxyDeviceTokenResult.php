<?php

namespace App\Support;

class NativeProxyDeviceTokenResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $token = null,
        public readonly ?int $expiresIn = null,
        public readonly ?int $pollingIntervalSeconds = null,
        public readonly ?string $deviceAuthorizationId = null,
    ) {}

    public function isIssued(): bool
    {
        return $this->token !== null;
    }
}
