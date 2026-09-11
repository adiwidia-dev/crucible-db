<?php

namespace App\Services\NativeProxy;

readonly class NativeStatementOutcome
{
    public function __construct(
        public bool $succeeded,
        public ?int $rowCount = null,
        public ?string $errorMessage = null,
        public ?int $durationMilliseconds = null,
    ) {}
}
