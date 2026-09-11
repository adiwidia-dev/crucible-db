<?php

namespace App\Services\NativeProxy;

readonly class NativeStatementData
{
    public function __construct(
        public string $sql,
        public string $protocolCommand,
        public int $parameterCount = 0,
    ) {}
}
