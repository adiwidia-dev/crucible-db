<?php

namespace App\Services\NativeProxy;

use App\Enums\QueryType;

readonly class ProxyStatementDecision
{
    public function __construct(
        public bool $allowed,
        public string $code,
        public string $message,
        public ?QueryType $queryType = null,
        public bool $sessionCommand = false,
        public ?string $resultFilter = null,
    ) {}

    public static function allowSession(?string $resultFilter = null): self
    {
        return new self(true, 'allowed_session_command', 'Allowed native session command.', sessionCommand: true, resultFilter: $resultFilter);
    }

    public static function allowQuery(QueryType $queryType): self
    {
        return new self(true, 'allowed_query', 'Allowed governed SQL statement.', $queryType);
    }

    public static function deny(string $code = 'unsupported_native_statement'): self
    {
        return new self(false, $code, 'This SQL statement is not allowed in a Native Client Access session.');
    }
}
