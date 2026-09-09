<?php

namespace App\Services\NativeProxy;

use App\Enums\ExecutionStatus;
use App\Enums\QueryType;
use App\Models\NativeProxyConnection;
use App\Models\QueryExecution;
use App\Models\QuerySessionQuery;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StatementWorkflow
{
    public function __construct(
        private readonly ProxyStatementPolicy $policy,
        private readonly ConnectionAdmission $admission,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function validateTemplate(NativeProxyConnection $connection, NativeStatementData $data): ProxyStatementDecision
    {
        if (preg_match('/\/\*[!+]/', $data->sql) === 1) {
            return ProxyStatementDecision::deny('executable_comment');
        }

        return $this->policy->decide(
            $connection->protocol,
            $connection->lease->access_mode,
            $connection->databaseConnection->database,
            $data->sql,
        );
    }

    /**
     * @throws ValidationException
     */
    public function authorizeExecution(NativeProxyConnection $connection, NativeStatementData $data): QuerySessionQuery
    {
        $blockedDecision = null;

        try {
            return DB::transaction(function () use ($connection, $data, &$blockedDecision): QuerySessionQuery {
                $connection = NativeProxyConnection::query()
                    ->with(['lease', 'databaseConnection'])
                    ->lockForUpdate()
                    ->findOrFail($connection->id);
                if (! $this->admission->heartbeat($connection)->continueConnection) {
                    throw ValidationException::withMessages(['sql' => 'Native session access is no longer allowed.']);
                }
                $decision = $this->validateTemplate($connection, $data);
                if (! $decision->allowed || ($decision->queryType === null && ! $decision->sessionCommand)) {
                    $blockedDecision = $decision;
                    throw ValidationException::withMessages(['sql' => $decision->message]);
                }

                $queryType = $decision->queryType ?? QueryType::Read;
                $template = $this->normalizeTemplate($data->sql, $connection->protocol->value);
                $fingerprint = hash('sha256', $template);
                $statement = QuerySessionQuery::query()->create([
                    'query_session_id' => $connection->query_session_id,
                    'native_proxy_connection_id' => $connection->id,
                    'database_connection_id' => $connection->database_connection_id,
                    'user_id' => $connection->user_id,
                    'sql' => $template,
                    'native_protocol_command' => $data->protocolCommand,
                    'native_sql_fingerprint' => $fingerprint,
                    'native_parameter_count' => $data->parameterCount,
                    'query_type' => $queryType,
                    'status' => ExecutionStatus::Running,
                    'started_at' => now(),
                    'result_truncated' => false,
                ]);
                QueryExecution::query()->create([
                    'native_query_session_query_id' => $statement->id,
                    'query_request_id' => $connection->query_request_id,
                    'native_proxy_connection_id' => $connection->id,
                    'database_connection_id' => $connection->database_connection_id,
                    'executed_by_id' => $connection->user_id,
                    'sql' => $template,
                    'native_protocol_command' => $data->protocolCommand,
                    'native_sql_fingerprint' => $fingerprint,
                    'native_parameter_count' => $data->parameterCount,
                    'query_type' => $queryType,
                    'status' => ExecutionStatus::Running,
                    'started_at' => now(),
                    'result_truncated' => false,
                ]);

                $connection->increment('statement_count');

                return $statement;
            }, attempts: 3);
        } catch (ValidationException $exception) {
            if ($blockedDecision instanceof ProxyStatementDecision) {
                $this->auditLogger->log('native_proxy.statement_blocked', null, $connection, [
                    'code' => $blockedDecision->code,
                    'protocol_command' => $data->protocolCommand,
                    'sql_fingerprint' => hash('sha256', $this->normalizeTemplate($data->sql, $connection->protocol->value)),
                    'parameter_count' => $data->parameterCount,
                ]);
            }

            throw $exception;
        }
    }

    public function complete(QuerySessionQuery $statement, NativeStatementOutcome $outcome): void
    {
        DB::transaction(function () use ($statement, $outcome): void {
            $statement = QuerySessionQuery::query()->lockForUpdate()->findOrFail($statement->id);
            if ($statement->status !== ExecutionStatus::Running) {
                return;
            }
            $status = $outcome->succeeded ? ExecutionStatus::Succeeded : ExecutionStatus::Failed;
            $values = [
                'status' => $status,
                'finished_at' => now(),
                'duration_ms' => $outcome->durationMilliseconds,
                'row_count' => $outcome->rowCount,
                'error_message' => $this->sanitizeErrorMessage($outcome->errorMessage),
                'sample_rows' => null,
            ];
            $statement->forceFill($values)->save();
            QueryExecution::query()
                ->where('native_query_session_query_id', $statement->id)
                ->where('status', ExecutionStatus::Running)
                ->lockForUpdate()
                ->first()?->forceFill($values)->save();
        }, attempts: 3);
    }

    private function normalizeTemplate(string $sql, string $protocol): string
    {
        $withoutComments = preg_replace('/(?:--[^\r\n]*|\/\*.*?\*\/)/s', ' ', $sql) ?? $sql;
        $withoutDollarQuotes = preg_replace('/\$[A-Za-z_][A-Za-z0-9_]*\$.*?\$[A-Za-z_][A-Za-z0-9_]*\$|\$\$.*?\$\$/s', "'?'", $withoutComments) ?? $withoutComments;
        $withoutStrings = preg_replace("/(?<![A-Za-z0-9_])(?:E|U&|B|X|N)?'(?:''|\\\\.|[^'])*'/i", "'?'", $withoutDollarQuotes) ?? $withoutDollarQuotes;
        if ($protocol === 'mysql') {
            $withoutStrings = preg_replace('/"(?:""|\\\\.|[^"])*"/', "'?'", $withoutStrings) ?? $withoutStrings;
        }
        $withoutStrings = preg_replace('/(?<![A-Za-z0-9_])0[xb][A-Fa-f0-9]+(?![A-Za-z0-9_])/', '?', $withoutStrings) ?? $withoutStrings;
        $withoutNumbers = preg_replace('/(?<![A-Za-z_])(?:\d+(?:\.\d+)?)(?![A-Za-z_])/', '?', $withoutStrings) ?? $withoutStrings;

        return trim((string) preg_replace('/\s+/', ' ', $withoutNumbers));
    }

    private function sanitizeErrorMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return 'Native database statement failed.';
    }
}
