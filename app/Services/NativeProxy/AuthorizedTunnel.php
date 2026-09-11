<?php

namespace App\Services\NativeProxy;

readonly class AuthorizedTunnel
{
    public function __construct(
        public string $authAttemptId,
        public string $leaseId,
        public string $proxyConnectionId,
        public string $protocol,
        public int $credentialVersion,
        public string $protocolAuthenticationSecret,
    ) {}
}
