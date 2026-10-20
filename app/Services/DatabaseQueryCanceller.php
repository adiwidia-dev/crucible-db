<?php

namespace App\Services;

use App\Enums\DatabaseDriver;
use App\Models\QuerySessionQuery;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

class DatabaseQueryCanceller
{
    public function __construct(private readonly DatabaseQueryExecutor $executor) {}

    public function cancel(QuerySessionQuery $query): bool
    {
        $query->loadMissing('databaseConnection');

        if ($query->databaseConnection === null || $query->backend_process_id === null || $query->execution_token === null) {
            return false;
        }

        return $this->executor->withRuntimeConnection(
            $query->databaseConnection,
            fn (ConnectionInterface $connection): bool => match ($query->databaseConnection->driver) {
                DatabaseDriver::PostgreSql => $this->cancelPostgreSql($connection, $query),
                DatabaseDriver::MySql => $this->cancelMySql($connection, $query),
            },
            'cancel-'.$query->execution_token,
        );
    }

    private function cancelPostgreSql(ConnectionInterface $connection, QuerySessionQuery $query): bool
    {
        $processId = (int) $query->backend_process_id;
        $process = $connection->selectOne(
            'select pid from pg_stat_activity where pid = ? and application_name = ?',
            [$processId, $query->backend_application_name],
        );

        if ($process === null) {
            return false;
        }

        $result = $connection->selectOne('select pg_cancel_backend(?) as cancelled', [$processId]);

        if (! filter_var($result->cancelled ?? false, FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('PostgreSQL refused to cancel the tracked backend process.');
        }

        return true;
    }

    private function cancelMySql(ConnectionInterface $connection, QuerySessionQuery $query): bool
    {
        $processId = (int) $query->backend_process_id;
        $executionTag = 'crucible-query:'.$query->id.':'.$query->execution_token;
        $process = $connection->selectOne(
            'select id from information_schema.processlist where id = ? and user = ? and db = ? and info like ?',
            [
                $processId,
                $query->databaseConnection->username,
                $query->databaseConnection->database,
                '%'.$executionTag.'%',
            ],
        );

        if ($process === null) {
            return false;
        }

        $connection->unprepared('KILL QUERY '.$processId);

        return true;
    }
}
