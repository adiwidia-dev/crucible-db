<?php

namespace App\Services\NativeProxy;

readonly class ConnectionHeartbeatDecision
{
    public function __construct(
        public bool $continueConnection,
        public string $reason,
    ) {}
}
