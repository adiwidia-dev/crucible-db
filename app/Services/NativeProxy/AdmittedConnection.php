<?php

namespace App\Services\NativeProxy;

readonly class AdmittedConnection
{
    public function __construct(
        public string $connectionId,
        public string $leaseId,
        public string $userId,
        public string $protocol,
        public int $credentialVersion,
        public bool $readOnly,
    ) {}
}
