<?php

namespace App\Services\NativeProxy;

readonly class NativeProxyUpstreamMaterial
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $tlsMode,
        public ?string $tlsCaCertificate,
        public ?string $tlsClientCertificate,
        public ?string $tlsClientKey,
    ) {}
}
