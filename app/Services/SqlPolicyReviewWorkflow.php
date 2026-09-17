<?php

namespace App\Services;

use App\Models\QueryRequest;
use App\Models\SqlPolicyCandidate;
use App\Models\SqlPolicyCandidateOccurrence;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SqlPolicyReviewWorkflow
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NotificationDispatcher $notificationDispatcher,
    ) {}

    /**
     * @param  array{status:mixed,summary:array{blocker_count:int,warning_count:int},statements:array<int, array{messages:array<int, array{level:string,code:string,candidate_id?:int}>}>}  $report
     */
    public function request(QueryRequest $queryRequest, User $actor, array $report): void
    {
        $blockedMessages = collect($report['statements'])
            ->flatMap(fn (array $statement) => $statement['messages'])
            ->filter(fn (array $message): bool => $message['level'] === 'blocked')
            ->values();
        $allBlockersAreReviewable = $blockedMessages->every(
            fn (array $message): bool => $message['code'] === 'unsupported_sql_policy'
                && isset($message['candidate_id']),
        );
        $candidateIds = $blockedMessages
            ->pluck('candidate_id')
            ->filter()
            ->map(fn (mixed $candidateId): int => (int) $candidateId)
            ->unique()
            ->values();

        if ($blockedMessages->isEmpty() || ! $allBlockersAreReviewable || $candidateIds->isEmpty()) {
            throw ValidationException::withMessages([
                'statements' => 'SQL policy review is available only when every blocker is an unsupported, reviewable SQL statement.',
            ]);
        }

        $unresolvedCandidateIds = SqlPolicyCandidate::query()
            ->whereKey($candidateIds)
            ->whereNull('resolution')
            ->lockForUpdate()
            ->pluck('id');

        if ($unresolvedCandidateIds->count() !== $candidateIds->count()) {
            throw ValidationException::withMessages([
                'statements' => 'One or more SQL policy candidates were already resolved. Run preflight again before requesting review.',
            ]);
        }

        $currentStatementIds = $queryRequest->statements()->pluck('id');
        SqlPolicyCandidateOccurrence::query()
            ->where('query_request_id', $queryRequest->id)
            ->where(fn ($query) => $query
                ->whereNull('query_request_statement_id')
                ->orWhereNotIn('query_request_statement_id', $currentStatementIds))
            ->update([
                'review_requested_by_id' => null,
                'review_requested_at' => null,
            ]);

        $occurrences = SqlPolicyCandidateOccurrence::query()
            ->where('query_request_id', $queryRequest->id)
            ->whereIn('query_request_statement_id', $currentStatementIds)
            ->whereIn('sql_policy_candidate_id', $candidateIds)
            ->get();

        if ($occurrences->pluck('sql_policy_candidate_id')->unique()->count() !== $candidateIds->count()) {
            throw ValidationException::withMessages([
                'statements' => 'The reviewable SQL candidates could not be linked to this draft. Run preflight again.',
            ]);
        }

        $isNewRequest = $occurrences->contains(
            fn (SqlPolicyCandidateOccurrence $occurrence): bool => $occurrence->review_requested_at === null,
        );

        $occurrences->each(function (SqlPolicyCandidateOccurrence $occurrence) use ($actor): void {
            $occurrence->forceFill([
                'review_requested_by_id' => $actor->id,
                'review_requested_at' => $occurrence->review_requested_at ?? now(),
            ])->save();
        });

        if (! $isNewRequest) {
            return;
        }

        $this->auditLogger->log('sql_policy.review_requested', $actor, $queryRequest, [
            'candidate_ids' => $candidateIds->all(),
            'statement_count' => $occurrences->count(),
        ]);
        $this->notificationDispatcher->sqlPolicyReviewRequested(
            $queryRequest,
            $candidateIds->count(),
            $candidateIds->first(),
        );
    }
}
