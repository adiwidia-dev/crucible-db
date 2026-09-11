<?php

namespace App\Services;

use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\QueryRequestStatement;
use App\Models\SqlPolicyCandidate;
use App\Models\SqlPolicyCandidateOccurrence;
use App\Models\User;
use App\Notifications\OperationalNotification;

class SqlPolicyCandidateRecorder
{
    public function __construct(
        private readonly SqlPolicyStatementAnalyzer $analyzer,
        private readonly ApplicationSettings $settings,
    ) {}

    /**
     * @param  array{signature:string,label:string,match_value:string}|null  $shape
     */
    public function record(
        QueryRequest $queryRequest,
        QueryRequestStatement $statement,
        DatabaseConnection $connection,
        string $sql,
        ?array $shape,
    ): SqlPolicyCandidate {
        $now = now();
        $fingerprint = $this->analyzer->exactFingerprint($sql);
        $inserted = SqlPolicyCandidate::query()->insertOrIgnore([
            'database_driver' => $connection->driver->value,
            'exact_fingerprint' => $fingerprint,
            'canonical_sql' => $this->analyzer->canonicalSql($sql),
            'shape_signature' => $shape['signature'] ?? null,
            'shape_label' => $shape['label'] ?? null,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $candidate = SqlPolicyCandidate::query()
            ->where('database_driver', $connection->driver->value)
            ->where('exact_fingerprint', $fingerprint)
            ->firstOrFail();

        $candidate->forceFill([
            'canonical_sql' => $this->analyzer->canonicalSql($sql),
            'shape_signature' => $shape['signature'] ?? null,
            'shape_label' => $shape['label'] ?? null,
            'last_seen_at' => $now,
        ])->save();

        SqlPolicyCandidateOccurrence::query()->updateOrCreate(
            [
                'sql_policy_candidate_id' => $candidate->id,
                'query_request_statement_id' => $statement->id,
            ],
            [
                'query_request_id' => $queryRequest->id,
                'database_connection_id' => $connection->id,
                'last_seen_at' => $now,
            ],
        );

        if ($inserted === 1) {
            $this->notifyAdministrators($candidate, $queryRequest);
        }

        return $candidate;
    }

    private function notifyAdministrators(SqlPolicyCandidate $candidate, QueryRequest $queryRequest): void
    {
        if (! $this->settings->notificationsInAppEnabled()) {
            return;
        }

        User::query()
            ->whereNull('disabled_at')
            ->whereHas('roles', fn ($query) => $query->where('is_admin', true))
            ->get()
            ->each(fn (User $administrator) => $administrator->notify(new OperationalNotification(
                [
                    'event' => 'sql_policy.candidate_discovered',
                    'severity' => 'warning',
                    'title' => 'SQL policy candidate discovered',
                    'message' => "An unsupported {$candidate->database_driver->value} statement was found in {$queryRequest->title}.",
                    'action_label' => 'Review candidate',
                    'url' => route('sql-statement-policy.edit', ['candidate' => $candidate->id]),
                    'request_id' => $queryRequest->id,
                ],
                true,
                false,
            )));
    }
}
