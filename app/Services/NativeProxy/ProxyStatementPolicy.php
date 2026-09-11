<?php

namespace App\Services\NativeProxy;

use App\Enums\AccessMode;
use App\Enums\DatabaseDriver;
use App\Enums\QueryType;
use App\Services\QueryGuard;
use Illuminate\Validation\ValidationException;

class ProxyStatementPolicy
{
    public function __construct(
        private readonly QueryGuard $queryGuard,
        private readonly PostgreSqlSessionCommandPolicy $postgreSqlSessionCommands,
        private readonly MySqlSessionCommandPolicy $mySqlSessionCommands,
    ) {}

    public function decide(DatabaseDriver $driver, AccessMode $accessMode, string $database, string $sql): ProxyStatementDecision
    {
        $sessionDecision = match ($driver) {
            DatabaseDriver::PostgreSql => $this->postgreSqlSessionCommands->decide($accessMode, $sql),
            DatabaseDriver::MySql => $this->mySqlSessionCommands->decide($accessMode, $sql, $database),
        };
        if ($sessionDecision !== null) {
            return $sessionDecision;
        }

        try {
            $statement = $this->queryGuard->validateSessionExecutable($sql);
            $queryType = $this->queryGuard->classify($statement);
        } catch (ValidationException) {
            return ProxyStatementDecision::deny();
        }

        if ($accessMode === AccessMode::Read && $queryType !== QueryType::Read) {
            return ProxyStatementDecision::deny('read_only_session');
        }

        return ProxyStatementDecision::allowQuery($queryType);
    }
}
