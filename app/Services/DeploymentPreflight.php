<?php

namespace App\Services;

use App\Enums\PreflightStatus;
use App\Enums\QueryType;
use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\QueryRequestStatement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class DeploymentPreflight
{
    public function __construct(private readonly QueryGuard $queryGuard) {}

    /**
     * Evaluate the executable statements persisted on a deployment batch.
     *
     * @return array{status:PreflightStatus,checked_at:string,summary:array{blocker_count:int,warning_count:int},statements:array<int, array{position:int,connection_id:int|null,connection_name:string|null,query_type:string|null,status:'passed'|'warning'|'blocked',messages:array<int, array{level:'warning'|'blocked',code:string,message:string}>}>}
     */
    public function evaluate(QueryRequest $queryRequest): array
    {
        $queryRequest->loadMissing('requester', 'databaseConnection', 'statements.databaseConnection');

        $statements = $queryRequest->statements;

        if ($statements->isEmpty()) {
            $statements = new Collection([
                new QueryRequestStatement([
                    'position' => 1,
                    'sql' => $queryRequest->sql,
                    'query_type' => $queryRequest->query_type,
                    'database_connection_id' => $queryRequest->database_connection_id,
                ]),
            ]);
        }

        $results = $statements
            ->map(fn (QueryRequestStatement $statement): array => $this->evaluateStatement(
                $queryRequest->requester,
                $statement,
                $statement->databaseConnection ?? $queryRequest->databaseConnection,
            ))
            ->values()
            ->all();

        $blockerCount = collect($results)
            ->sum(fn (array $statement): int => count(array_filter(
                $statement['messages'],
                fn (array $message): bool => $message['level'] === 'blocked',
            )));
        $warningCount = collect($results)
            ->sum(fn (array $statement): int => count(array_filter(
                $statement['messages'],
                fn (array $message): bool => $message['level'] === 'warning',
            )));

        return [
            'status' => $blockerCount > 0
                ? PreflightStatus::Blocked
                : ($warningCount > 0 ? PreflightStatus::PassedWithWarnings : PreflightStatus::Passed),
            'checked_at' => now()->toIso8601String(),
            'summary' => [
                'blocker_count' => $blockerCount,
                'warning_count' => $warningCount,
            ],
            'statements' => $results,
        ];
    }

    /**
     * Persist the latest evaluation without affecting the request lifecycle.
     *
     * @param  array{status:PreflightStatus,checked_at:string,summary:array{blocker_count:int,warning_count:int},statements:array<int, array{position:int,connection_id:int|null,connection_name:string|null,query_type:string|null,status:'passed'|'warning'|'blocked',messages:array<int, array{level:'warning'|'blocked',code:string,message:string}>}>}  $report
     */
    public function persist(QueryRequest $queryRequest, array $report): void
    {
        $queryRequest->forceFill([
            'preflight_status' => $report['status'],
            'preflight_report' => [
                'checked_at' => $report['checked_at'],
                'summary' => $report['summary'],
                'statements' => $report['statements'],
            ],
            'preflight_checked_at' => $report['checked_at'],
        ])->save();
    }

    /**
     * @return array{position:int,connection_id:int|null,connection_name:string|null,query_type:string|null,status:'passed'|'warning'|'blocked',messages:array<int, array{level:'warning'|'blocked',code:string,message:string}>}
     */
    private function evaluateStatement(User $requester, QueryRequestStatement $statement, ?DatabaseConnection $connection): array
    {
        $messages = [];
        $queryType = $statement->query_type;

        try {
            $sql = $this->queryGuard->validateExecutable($statement->sql);
            $queryType = $this->queryGuard->classify($sql);
        } catch (ValidationException $exception) {
            $messages[] = $this->message(
                'blocked',
                'invalid_sql',
                collect($exception->errors())->flatten()->first() ?? 'This SQL statement is invalid.',
            );
            $sql = $statement->sql;
        }

        if (! $connection instanceof DatabaseConnection) {
            $messages[] = $this->message(
                'blocked',
                'missing_target',
                'The selected database connection is no longer available.',
            );
        } elseif (! $connection->is_active) {
            $messages[] = $this->message(
                'blocked',
                'inactive_target',
                "{$connection->name} is inactive and cannot accept a deployment.",
            );
        } elseif (! $requester->isAdmin() && $queryType instanceof QueryType) {
            $permission = $requester->effectiveDatabasePermissionFor($connection, $queryType);

            if (! $permission['access_mode']->allows($queryType)) {
                $messages[] = $this->message(
                    'blocked',
                    'policy_changed',
                    "The requester no longer has {$queryType->value} access to {$connection->name}.",
                );
            }
        }

        if ($queryType === QueryType::Write && preg_match('/^(update|delete)\b[\s\S]*\bwhere\b/i', $sql) !== 1) {
            $messages[] = $this->message(
                'warning',
                'unbounded_write',
                'This UPDATE or DELETE statement has no WHERE clause and may affect every row.',
            );
        }

        if ($queryType === QueryType::Read
            && preg_match('/^select\b[\s\S]*\bfrom\b/i', $sql) === 1
            && preg_match('/\blimit\b/i', $sql) !== 1) {
            $messages[] = $this->message(
                'warning',
                'unbounded_read',
                'This SELECT statement has no LIMIT. Results are capped at execution time, but the source query may still scan a large table.',
            );
        }

        $status = collect($messages)->contains(fn (array $message): bool => $message['level'] === 'blocked')
            ? 'blocked'
            : (count($messages) > 0 ? 'warning' : 'passed');

        return [
            'position' => $statement->position,
            'connection_id' => $connection?->id,
            'connection_name' => $connection?->name,
            'query_type' => $queryType?->value,
            'status' => $status,
            'messages' => $messages,
        ];
    }

    /**
     * @return array{level:'warning'|'blocked',code:string,message:string}
     */
    private function message(string $level, string $code, string $message): array
    {
        return [
            'level' => $level,
            'code' => $code,
            'message' => $message,
        ];
    }
}
