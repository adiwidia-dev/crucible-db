<?php

namespace App\Services;

use App\Enums\AccessMode;
use App\Enums\QueryRequestKind;
use App\Enums\QueryRequestStatus;
use App\Enums\QueryType;
use App\Jobs\ExecuteQueryRequest;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\QueryReview;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class QueryRequestWorkflow
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly QueryGuard $queryGuard,
    ) {}

    /**
     * @param  DatabaseConnection|array{database_connection_id?:int,database_connection_ids?:array<int, int>,request_kind:string,title:string,description?:string|null,sql?:string|null,statements?:array<int, array{sql:string,database_connection_id:int}>,scheduled_at?:string|null,access_duration_minutes?:int|null}  $databaseConnectionOrData
     * @param  array{database_connection_id?:int,database_connection_ids?:array<int, int>,request_kind:string,title:string,description?:string|null,sql?:string|null,statements?:array<int, array{sql:string,database_connection_id:int}>,scheduled_at?:string|null,access_duration_minutes?:int|null}|null  $data
     *
     * @throws ValidationException
     */
    public function create(User $requester, DatabaseConnection|array $databaseConnectionOrData, ?array $data = null): QueryRequest
    {
        if ($databaseConnectionOrData instanceof DatabaseConnection) {
            if ($data === null) {
                throw new InvalidArgumentException('Query request data is required when creating a request for a database connection.');
            }

            $data['database_connection_id'] ??= $databaseConnectionOrData->id;
            $data['database_connection_ids'] ??= [$databaseConnectionOrData->id];
        } else {
            $data = $databaseConnectionOrData;
        }

        $requestKind = QueryRequestKind::from($data['request_kind']);
        $statements = $requestKind === QueryRequestKind::SingleExecution
            ? $this->validateStatements($this->statementInput($data))
            : [];
        $databaseConnections = $requestKind === QueryRequestKind::SingleExecution
            ? $this->databaseConnectionsForStatements($statements)
            : $this->databaseConnectionsForAccess($data);
        $databaseConnection = $requestKind === QueryRequestKind::SingleExecution
            ? $databaseConnections->get($statements[0]['database_connection_id'])
            : $databaseConnections->first();

        if (! $databaseConnection instanceof DatabaseConnection) {
            throw ValidationException::withMessages([
                'database_connection_id' => 'Select a valid database connection.',
            ]);
        }

        $queryType = $this->batchQueryType($statements);

        if (! $requester->isAdmin()) {
            if ($requestKind === QueryRequestKind::SingleExecution) {
                foreach ($statements as $index => $statement) {
                    $permission = $requester->effectiveDatabasePermission($databaseConnections->get($statement['database_connection_id']));

                    if (! $permission['access_mode']->allows($statement['query_type'])) {
                        throw ValidationException::withMessages([
                            "statements.{$index}.database_connection_id" => 'Your role is not allowed to run this query type on the selected database.',
                        ]);
                    }
                }
            }

            if ($requestKind === QueryRequestKind::QueryAccess) {
                foreach ($databaseConnections as $connection) {
                    if ($requester->effectiveDatabasePermission($connection)['access_mode'] === AccessMode::None) {
                        throw ValidationException::withMessages([
                            'database_connection_ids' => 'Your role is not allowed to access every selected database.',
                        ]);
                    }
                }
            }
        }

        $requiresApproval = ! $requester->isAdmin() && $databaseConnections
            ->contains(fn (DatabaseConnection $connection): bool => $requester->effectiveDatabasePermission($connection)['requires_approval']);
        $scheduledAt = $requestKind === QueryRequestKind::SingleExecution && filled($data['scheduled_at'] ?? null)
            ? Carbon::parse($data['scheduled_at'])
            : null;
        $accessDurationMinutes = $requestKind === QueryRequestKind::QueryAccess
            ? (int) ($data['access_duration_minutes'] ?? 60)
            : null;

        return DB::transaction(function () use ($requester, $databaseConnection, $databaseConnections, $data, $requestKind, $queryType, $statements, $requiresApproval, $scheduledAt, $accessDurationMinutes): QueryRequest {
            $status = QueryRequestStatus::PendingReview;

            if (! $requiresApproval) {
                $status = $scheduledAt?->isFuture() ? QueryRequestStatus::Scheduled : QueryRequestStatus::Approved;
            }

            $queryRequest = QueryRequest::query()->create([
                'requester_id' => $requester->id,
                'database_connection_id' => $databaseConnection->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'sql' => $statements[0]['sql'] ?? '',
                'query_type' => $queryType,
                'request_kind' => $requestKind,
                'status' => $status,
                'requires_approval' => $requiresApproval,
                'scheduled_at' => $scheduledAt,
                'access_duration_minutes' => $accessDurationMinutes,
                'approved_by_id' => $requiresApproval ? null : $requester->id,
                'approved_at' => $requiresApproval ? null : now(),
            ]);

            $this->replaceStatements($queryRequest, $statements);
            $queryRequest->accessConnections()->sync(
                $requestKind === QueryRequestKind::QueryAccess
                    ? $databaseConnections->pluck('id')->all()
                    : [],
            );

            $this->auditLogger->log('query_request.created', $requester, $queryRequest, [
                'status' => $queryRequest->status->value,
                'query_type' => $queryRequest->query_type->value,
                'request_kind' => $queryRequest->request_kind->value,
                'database_connection_ids' => $databaseConnections->pluck('id')->values()->all(),
                'statement_count' => count($statements),
            ]);

            return $queryRequest;
        });
    }

    /**
     * @param  array{database_connection_id?:int,database_connection_ids?:array<int, int>,request_kind:string,title:string,description?:string|null,sql?:string|null,statements?:array<int, array{sql:string,database_connection_id:int}>,scheduled_at?:string|null,access_duration_minutes?:int|null}  $data
     *
     * @throws ValidationException
     */
    public function update(QueryRequest $queryRequest, User $actor, array $data): QueryRequest
    {
        $requestKind = QueryRequestKind::from($data['request_kind']);
        $statements = $requestKind === QueryRequestKind::SingleExecution
            ? $this->validateStatements($this->statementInput($data))
            : [];
        $databaseConnections = $requestKind === QueryRequestKind::SingleExecution
            ? $this->databaseConnectionsForStatements($statements)
            : $this->databaseConnectionsForAccess($data);
        $databaseConnection = $requestKind === QueryRequestKind::SingleExecution
            ? $databaseConnections->get($statements[0]['database_connection_id'])
            : $databaseConnections->first();

        if (! $databaseConnection instanceof DatabaseConnection) {
            throw ValidationException::withMessages([
                'database_connection_id' => 'Select a valid database connection.',
            ]);
        }

        $queryType = $this->batchQueryType($statements);

        if (! $actor->isAdmin()) {
            if ($requestKind === QueryRequestKind::SingleExecution) {
                foreach ($statements as $index => $statement) {
                    $permission = $actor->effectiveDatabasePermission($databaseConnections->get($statement['database_connection_id']));

                    if (! $permission['access_mode']->allows($statement['query_type'])) {
                        throw ValidationException::withMessages([
                            "statements.{$index}.database_connection_id" => 'Your role is not allowed to run this query type on the selected database.',
                        ]);
                    }
                }
            }

            if ($requestKind === QueryRequestKind::QueryAccess) {
                foreach ($databaseConnections as $connection) {
                    if ($actor->effectiveDatabasePermission($connection)['access_mode'] === AccessMode::None) {
                        throw ValidationException::withMessages([
                            'database_connection_ids' => 'Your role is not allowed to access every selected database.',
                        ]);
                    }
                }
            }
        }

        $scheduledAt = $requestKind === QueryRequestKind::SingleExecution && filled($data['scheduled_at'] ?? null)
            ? Carbon::parse($data['scheduled_at'])
            : null;
        $accessDurationMinutes = $requestKind === QueryRequestKind::QueryAccess
            ? (int) ($data['access_duration_minutes'] ?? 60)
            : null;

        return DB::transaction(function () use ($queryRequest, $actor, $databaseConnection, $databaseConnections, $data, $requestKind, $statements, $queryType, $scheduledAt, $accessDurationMinutes): QueryRequest {
            $lockedQueryRequest = QueryRequest::query()->lockForUpdate()->findOrFail($queryRequest->id);

            if (! $lockedQueryRequest->isEditable()) {
                throw ValidationException::withMessages([
                    'query_request' => 'This query request can no longer be edited.',
                ]);
            }

            $previousStatus = $lockedQueryRequest->status;

            $lockedQueryRequest->forceFill([
                'database_connection_id' => $databaseConnection->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'sql' => $statements[0]['sql'] ?? '',
                'query_type' => $queryType,
                'request_kind' => $requestKind,
                'status' => QueryRequestStatus::PendingReview,
                'requires_approval' => true,
                'scheduled_at' => $scheduledAt,
                'access_duration_minutes' => $accessDurationMinutes,
                'approved_by_id' => null,
                'approved_at' => null,
                'dispatched_by_id' => null,
                'dispatched_at' => null,
                'completed_at' => null,
                'result_summary' => null,
                'last_error' => null,
            ])->save();

            $this->replaceStatements($lockedQueryRequest, $statements);
            $lockedQueryRequest->accessConnections()->sync(
                $requestKind === QueryRequestKind::QueryAccess
                    ? $databaseConnections->pluck('id')->all()
                    : [],
            );

            $this->auditLogger->log('query_request.updated', $actor, $lockedQueryRequest, [
                'previous_status' => $previousStatus->value,
                'approval_invalidated' => in_array($previousStatus, [QueryRequestStatus::Approved, QueryRequestStatus::Scheduled], true),
                'status' => $lockedQueryRequest->status->value,
                'query_type' => $lockedQueryRequest->query_type->value,
                'request_kind' => $lockedQueryRequest->request_kind->value,
                'database_connection_ids' => $databaseConnections->pluck('id')->values()->all(),
                'statement_count' => count($statements),
            ]);

            return $lockedQueryRequest->refresh();
        });
    }

    /**
     * @throws ValidationException
     */
    public function review(QueryRequest $queryRequest, User $reviewer, string $decision, ?string $comment = null): QueryReview
    {
        if ($queryRequest->status !== QueryRequestStatus::PendingReview) {
            throw ValidationException::withMessages([
                'decision' => 'Only pending requests can be reviewed.',
            ]);
        }

        return DB::transaction(function () use ($queryRequest, $reviewer, $decision, $comment): QueryReview {
            $review = QueryReview::query()->create([
                'query_request_id' => $queryRequest->id,
                'reviewer_id' => $reviewer->id,
                'decision' => $decision,
                'comment' => $comment,
            ]);

            if ($decision === 'approved') {
                $queryRequest->forceFill([
                    'status' => $queryRequest->scheduled_at?->isFuture() ? QueryRequestStatus::Scheduled : QueryRequestStatus::Approved,
                    'approved_by_id' => $reviewer->id,
                    'approved_at' => now(),
                ])->save();
            } else {
                $queryRequest->forceFill([
                    'status' => QueryRequestStatus::Rejected,
                    'completed_at' => now(),
                ])->save();
            }

            $this->auditLogger->log('query_request.reviewed', $reviewer, $queryRequest, [
                'decision' => $decision,
                'review_id' => $review->id,
            ]);

            return $review;
        });
    }

    public function dispatch(QueryRequest $queryRequest, ?User $actor = null): void
    {
        if ($queryRequest->request_kind !== QueryRequestKind::SingleExecution) {
            throw ValidationException::withMessages([
                'query_request' => 'Only single execution requests can be dispatched.',
            ]);
        }

        if ($queryRequest->status === QueryRequestStatus::Scheduled && $queryRequest->scheduled_at?->isFuture()) {
            throw ValidationException::withMessages([
                'query_request' => 'This query request is scheduled for a future execution time.',
            ]);
        }

        if ($queryRequest->dispatched_at !== null || ! in_array($queryRequest->status, [QueryRequestStatus::Approved, QueryRequestStatus::Scheduled], true)) {
            return;
        }

        $queryRequest->forceFill([
            'status' => QueryRequestStatus::Approved,
            'dispatched_at' => now(),
            'dispatched_by_id' => $actor?->id,
        ])->save();

        ExecuteQueryRequest::dispatch($queryRequest->id)->onQueue('queries')->afterCommit();

        $this->auditLogger->log('query_request.dispatched', $actor, $queryRequest);
    }

    /**
     * @param  array<int, array{sql?:string,database_connection_id?:int}>  $statements
     * @return array<int, array{position:int, sql:string, query_type:QueryType, database_connection_id:int}>
     *
     * @throws ValidationException
     */
    private function validateStatements(array $statements): array
    {
        if ($statements === []) {
            throw ValidationException::withMessages([
                'statements' => 'At least one SQL statement is required.',
            ]);
        }

        $validated = [];

        foreach (array_values($statements) as $index => $statement) {
            $databaseConnectionId = (int) ($statement['database_connection_id'] ?? 0);

            if ($databaseConnectionId < 1) {
                throw ValidationException::withMessages([
                    "statements.{$index}.database_connection_id" => 'Select a connection for this statement.',
                ]);
            }

            try {
                $sql = $this->queryGuard->validateExecutable((string) ($statement['sql'] ?? ''));
                $queryType = $this->queryGuard->classify($sql);
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first() ?? 'The SQL statement is invalid.';

                throw ValidationException::withMessages([
                    "statements.{$index}.sql" => $message,
                ]);
            }

            $validated[] = [
                'position' => $index + 1,
                'database_connection_id' => $databaseConnectionId,
                'sql' => $sql,
                'query_type' => $queryType,
            ];
        }

        return $validated;
    }

    /**
     * @param  array<int, array{position:int, sql:string, query_type:QueryType, database_connection_id:int}>  $statements
     */
    private function batchQueryType(array $statements): QueryType
    {
        return collect($statements)->contains(
            fn (array $statement): bool => $statement['query_type'] === QueryType::Write,
        ) ? QueryType::Write : QueryType::Read;
    }

    /**
     * @param  array<int, array{position:int, sql:string, query_type:QueryType, database_connection_id:int}>  $statements
     */
    private function replaceStatements(QueryRequest $queryRequest, array $statements): void
    {
        $queryRequest->statements()->delete();

        $queryRequest->statements()->createMany(array_map(
            fn (array $statement): array => [
                'position' => $statement['position'],
                'database_connection_id' => $statement['database_connection_id'],
                'sql' => $statement['sql'],
                'query_type' => $statement['query_type'],
            ],
            $statements,
        ));
    }

    /**
     * @param  array{database_connection_id?:int,sql?:string|null,statements?:array<int, array{sql:string,database_connection_id?:int}>}  $data
     * @return array<int, array{sql:string,database_connection_id?:int}>
     */
    private function statementInput(array $data): array
    {
        if (array_key_exists('statements', $data)) {
            return array_map(
                fn (array $statement): array => [
                    ...$statement,
                    'database_connection_id' => $statement['database_connection_id'] ?? $data['database_connection_id'] ?? null,
                ],
                $data['statements'],
            );
        }

        return filled($data['sql'] ?? null)
            ? [[
                'sql' => (string) $data['sql'],
                'database_connection_id' => $data['database_connection_id'] ?? null,
            ]]
            : [];
    }

    /**
     * @param  array<int, array{position:int, sql:string, query_type:QueryType, database_connection_id:int}>  $statements
     * @return Collection<int, DatabaseConnection>
     *
     * @throws ValidationException
     */
    private function databaseConnectionsForStatements(array $statements): Collection
    {
        $connectionIds = collect($statements)
            ->pluck('database_connection_id')
            ->unique()
            ->values();
        $databaseConnections = DatabaseConnection::query()
            ->whereKey($connectionIds)
            ->get()
            ->keyBy('id');

        if ($databaseConnections->count() !== $connectionIds->count()) {
            throw ValidationException::withMessages([
                'statements' => 'One or more selected connections are no longer available.',
            ]);
        }

        return $databaseConnections;
    }

    /**
     * @param  array{database_connection_id?:int,database_connection_ids?:array<int, int>}  $data
     * @return Collection<int, DatabaseConnection>
     *
     * @throws ValidationException
     */
    private function databaseConnectionsForAccess(array $data): Collection
    {
        $connectionIds = collect($data['database_connection_ids'] ?? [$data['database_connection_id'] ?? null])
            ->filter(fn (mixed $connectionId): bool => (int) $connectionId > 0)
            ->map(fn (mixed $connectionId): int => (int) $connectionId)
            ->unique()
            ->values();
        $databaseConnections = DatabaseConnection::query()
            ->whereKey($connectionIds)
            ->get()
            ->keyBy('id');

        if ($connectionIds->isEmpty() || $databaseConnections->count() !== $connectionIds->count()) {
            throw ValidationException::withMessages([
                'database_connection_ids' => 'Select one or more valid database connections.',
            ]);
        }

        return $databaseConnections;
    }
}
