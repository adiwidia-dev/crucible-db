<?php

namespace App\Services\NativeProxy;

use App\Enums\DatabaseDriver;

readonly class ConsumeAuthAttemptData
{
    public function __construct(
        public string $authAttemptId,
        public string $proxyInstanceId,
        public string $syntheticUsername,
        public string $syntheticPassword,
        public DatabaseDriver $protocol,
    ) {}
}
