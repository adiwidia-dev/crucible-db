<?php

namespace App\Services\NativeProxy;

use App\Enums\DatabaseDriver;

readonly class TunnelAuthorizationData
{
    public function __construct(
        public string $leaseId,
        public string $deviceAuthorizationId,
        public string $proxyConnectionId,
        public string $proxyInstanceId,
        public DatabaseDriver $protocol,
        public string $bearerTokenHash,
    ) {}
}
