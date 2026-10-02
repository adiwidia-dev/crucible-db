<?php

namespace App\Services;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
use App\Enums\QueryRequestKind;
use App\Enums\QueryType;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EffectiveQueryRequestPolicy
{
    /**
     * @param  Collection<int, DatabaseConnection>  $databaseConnections
     *
     * @throws ValidationException
     */
    public function ensureCanRequestAccess(User $user, Collection $databaseConnections, AccessMode $requestedAccessMode, AccessTransport $accessTransport): void
    {
        $this->ensureUserIsEnabled($user);

        if ($user->isAdmin()) {
            return;
        }

        foreach ($databaseConnections as $connection) {
            $queryType = $this->queryTypeForAccessMode($requestedAccessMode);
            $permission = $accessTransport === AccessTransport::NativeProxy
                ? $user->effectiveNativeProxyPermissionFor($connection, $queryType)
                : $user->effectiveQueryAccessPermissionFor($connection, $queryType);

            $accessMode = $accessTransport === AccessTransport::NativeProxy
                ? $permission['native_proxy_access_mode']
                : $permission['query_access_mode'];

            if (! $accessMode->allows($queryType)) {
                throw ValidationException::withMessages([
                    $accessTransport === AccessTransport::NativeProxy ? 'requested_access_mode' : 'database_connection_ids' => $accessTransport === AccessTransport::NativeProxy
                        ? 'Your role is not allowed to request the selected Native Client Access level for this database.'
                        : 'Your role is not allowed to request the selected session access level for every selected database.',
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, DatabaseConnection>  $databaseConnections
     * @param  array<int, array{database_connection_id:int, query_type:QueryType}>  $statements
     *
     * @throws ValidationException
     */
    public function ensureCanRunStatements(User $user, Collection $databaseConnections, array $statements): void
    {
        $this->ensureUserIsEnabled($user);

        if ($user->isAdmin()) {
            return;
        }

        foreach ($statements as $index => $statement) {
            $databaseConnection = $databaseConnections->get($statement['database_connection_id']);

            if (! $databaseConnection instanceof DatabaseConnection) {
                throw ValidationException::withMessages([
                    "statements.{$index}.database_connection_id" => 'The selected database connection is no longer available.',
                ]);
            }

            $permission = $user->effectiveDatabasePermissionFor($databaseConnection, $statement['query_type']);

            if (! $permission['access_mode']->allows($statement['query_type'])) {
                throw ValidationException::withMessages([
                    "statements.{$index}.database_connection_id" => 'Your role is not allowed to run this query type on the selected database.',
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, DatabaseConnection>  $databaseConnections
     * @param  array<int, array{database_connection_id:int, query_type:QueryType}>  $statements
     */
    public function requiresApproval(
        User $user,
        Collection $databaseConnections,
        array $statements,
        ?AccessMode $requestedAccessMode,
        AccessTransport $accessTransport = AccessTransport::Browser,
    ): bool {
        if ($user->isAdmin()) {
            return false;
        }

        if ($requestedAccessMode instanceof AccessMode) {
            $queryType = $this->queryTypeForAccessMode($requestedAccessMode);

            return $databaseConnections->contains(function (DatabaseConnection $connection) use ($user, $queryType, $accessTransport): bool {
                $permission = $accessTransport === AccessTransport::NativeProxy
                    ? $user->effectiveNativeProxyPermissionFor($connection, $queryType)
                    : $user->effectiveQueryAccessPermissionFor($connection, $queryType);

                return $queryType === QueryType::Read
                    ? $permission['read_requires_approval']
                    : $permission['write_requires_approval'];
            });
        }

        return collect($statements)->contains(function (array $statement) use ($user, $databaseConnections): bool {
            $queryType = $statement['query_type'];
            $connection = $databaseConnections->get($statement['database_connection_id']);

            if (! $connection instanceof DatabaseConnection) {
                return true;
            }

            $permission = $user->effectiveDatabasePermissionFor($connection, $queryType);

            return $queryType === QueryType::Read
                ? $permission['read_requires_approval']
                : $permission['write_requires_approval'];
        });
    }

    /**
     * @param  Collection<int, DatabaseConnection>  $databaseConnections
     *
     * @throws ValidationException
     */
    public function ensureWriteSessionDurationIsAllowed(
        User $user,
        Collection $databaseConnections,
        int $durationMinutes,
        AccessTransport $accessTransport = AccessTransport::Browser,
    ): void {
        $this->ensureUserIsEnabled($user);

        if ($user->isAdmin()) {
            return;
        }

        foreach ($databaseConnections as $connection) {
            $permission = $accessTransport === AccessTransport::NativeProxy
                ? $user->effectiveNativeProxyPermissionFor($connection, QueryType::Write)
                : $user->effectiveQueryAccessPermissionFor($connection, QueryType::Write);
            $maximumDuration = $permission['max_write_session_minutes'];

            if ($maximumDuration !== null && $durationMinutes > $maximumDuration) {
                throw ValidationException::withMessages([
                    'access_duration_minutes' => "Write sessions on {$connection->name} are limited to {$maximumDuration} minutes.",
                ]);
            }
        }
    }

    public function requestRequiresApproval(QueryRequest $queryRequest): bool
    {
        $queryRequest->loadMissing([
            'requester',
            'databaseConnection',
            'accessConnections',
            'statements.databaseConnection',
        ]);

        $databaseConnections = $this->databaseConnectionsFor($queryRequest);

        if ($queryRequest->request_kind === QueryRequestKind::QueryAccess) {
            return $this->requiresApproval(
                $queryRequest->requester,
                $databaseConnections,
                [],
                $queryRequest->requested_access_mode ?? AccessMode::Read,
                $queryRequest->access_transport,
            );
        }

        return $this->requiresApproval(
            $queryRequest->requester,
            $databaseConnections,
            $this->statementsFor($queryRequest),
            null,
            $queryRequest->access_transport,
        );
    }

    public function requestRequiresFreshApproval(QueryRequest $queryRequest): bool
    {
        if (! $this->requestRequiresApproval($queryRequest)) {
            return false;
        }

        return $queryRequest->approved_at === null
            || $queryRequest->approved_by_id === null
            || $queryRequest->approved_by_id === $queryRequest->requester_id;
    }

    /**
     * @throws ValidationException
     */
    public function ensureQueryAccessRequestCanStart(QueryRequest $queryRequest): void
    {
        $queryRequest->loadMissing([
            'requester',
            'databaseConnection',
            'accessConnections',
        ]);

        $databaseConnections = $this->databaseConnectionsFor($queryRequest);
        $requestedAccessMode = $queryRequest->requested_access_mode ?? AccessMode::Read;

        $this->ensureCanRequestAccess(
            $queryRequest->requester,
            $databaseConnections,
            $requestedAccessMode,
            $queryRequest->access_transport,
        );

        if ($requestedAccessMode === AccessMode::Write) {
            $this->ensureWriteSessionDurationIsAllowed(
                $queryRequest->requester,
                $databaseConnections,
                $queryRequest->access_duration_minutes ?? 60,
                $queryRequest->access_transport,
            );
        }
    }

    /**
     * @throws ValidationException
     */
    public function ensureUserIsEnabled(User $user): void
    {
        if ($user->isDisabled()) {
            throw ValidationException::withMessages([
                'query_request' => 'The requester account is disabled, so this query request cannot proceed.',
            ]);
        }
    }

    private function queryTypeForAccessMode(AccessMode $accessMode): QueryType
    {
        return $accessMode === AccessMode::Write ? QueryType::Write : QueryType::Read;
    }

    /**
     * @return Collection<int, DatabaseConnection>
     */
    private function databaseConnectionsFor(QueryRequest $queryRequest): Collection
    {
        if ($queryRequest->request_kind === QueryRequestKind::QueryAccess
            && $queryRequest->accessConnections->isNotEmpty()) {
            return $queryRequest->accessConnections->values();
        }

        $connections = $queryRequest->statements
            ->map(fn ($statement) => $statement->databaseConnection ?? $queryRequest->databaseConnection)
            ->filter()
            ->unique('id')
            ->keyBy('id');

        if ($connections->isNotEmpty()) {
            return $connections;
        }

        return collect([$queryRequest->databaseConnection])->keyBy('id');
    }

    /**
     * @return array<int, array{database_connection_id:int, query_type:QueryType}>
     */
    private function statementsFor(QueryRequest $queryRequest): array
    {
        if ($queryRequest->statements->isNotEmpty()) {
            return $queryRequest->statements
                ->map(fn ($statement): array => [
                    'database_connection_id' => $statement->database_connection_id,
                    'query_type' => $statement->query_type,
                ])
                ->all();
        }

        return [[
            'database_connection_id' => $queryRequest->database_connection_id,
            'query_type' => $queryRequest->query_type,
        ]];
    }
}
