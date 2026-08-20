<?php

namespace App\Services;

use App\Models\DatabaseConnection;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\User;
use App\Notifications\OperationalNotification;
use Illuminate\Support\Collection;

class NotificationDispatcher
{
    public function __construct(private readonly ApplicationSettings $settings) {}

    public function requestSubmitted(QueryRequest $queryRequest): void
    {
        if (! $this->settings->notificationEventEnabled('review')) {
            return;
        }

        $this->notify(
            $this->reviewersFor($queryRequest)
                ->merge($this->subscribersForQueryRequest($queryRequest))
                ->unique('id')
                ->values(),
            $this->requestPayload(
                'query_request.review_required',
                'warning',
                'Review requested',
                "{$queryRequest->title} needs approval before it can proceed.",
                $queryRequest,
            ),
            'approvals',
        );
    }

    public function reapprovalRequired(QueryRequest $queryRequest): void
    {
        if (! $this->settings->notificationEventEnabled('review')) {
            return;
        }

        $recipients = $this->reviewersFor($queryRequest)
            ->push($queryRequest->requester)
            ->merge($this->subscribersForQueryRequest($queryRequest))
            ->unique('id')
            ->values();

        $this->notify(
            $recipients,
            $this->requestPayload(
                'query_request.reapproval_required',
                'warning',
                'Reapproval required',
                "{$queryRequest->title} changed and requires a new review.",
                $queryRequest,
            ),
            'approvals',
        );
    }

    public function requestReviewed(QueryRequest $queryRequest, string $decision): void
    {
        if (! $this->settings->notificationEventEnabled('review')) {
            return;
        }

        $approved = $decision === 'approved';

        $this->notify(
            collect([$queryRequest->requester])
                ->merge($this->subscribersForQueryRequest($queryRequest))
                ->unique('id')
                ->values(),
            $this->requestPayload(
                "query_request.{$decision}",
                $approved ? 'success' : 'warning',
                $approved ? 'Request approved' : 'Request rejected',
                $approved
                    ? "{$queryRequest->title} is approved and ready for its next action."
                    : "{$queryRequest->title} was rejected and needs an update before it can proceed.",
                $queryRequest,
            ),
            'approvals',
        );
    }

    public function requestCancelled(QueryRequest $queryRequest, User $actor): void
    {
        if (! $this->settings->notificationEventEnabled('review')) {
            return;
        }

        $recipients = $this->requestActors($queryRequest)
            ->merge($this->reviewersFor($queryRequest))
            ->merge($this->subscribersForQueryRequest($queryRequest))
            ->unique('id')
            ->values();

        $this->notify(
            $recipients,
            $this->requestPayload(
                'query_request.cancelled',
                'warning',
                'Request cancelled',
                "{$queryRequest->title} was cancelled by {$actor->name}.",
                $queryRequest,
            ),
            'approvals',
        );
    }

    public function batchCompleted(QueryRequest $queryRequest): void
    {
        if (! $this->settings->notificationEventEnabled('execution_completed')) {
            return;
        }

        $this->notify(
            $this->requestActors($queryRequest)
                ->merge($this->subscribersForQueryRequest($queryRequest))
                ->unique('id')
                ->values(),
            $this->requestPayload(
                'query_request.execution_completed',
                'success',
                'Batch completed',
                "{$queryRequest->title} completed successfully.",
                $queryRequest,
            ),
            'execution_completed',
        );
    }

    public function batchFailed(QueryRequest $queryRequest, ?int $statementPosition = null): void
    {
        if (! $this->settings->notificationEventEnabled('execution_failed')) {
            return;
        }

        $recipients = $this->requestActors($queryRequest)
            ->merge($this->reviewersFor($queryRequest))
            ->merge($this->subscribersForQueryRequest($queryRequest))
            ->merge($this->operationalRecipients())
            ->unique('id')
            ->values();
        $statementText = $statementPosition === null ? '' : " at statement {$statementPosition}";

        $this->notify(
            $recipients,
            $this->requestPayload(
                'query_request.execution_failed',
                'critical',
                'Batch execution failed',
                "{$queryRequest->title} failed{$statementText}. Review the request for the governed execution record.",
                $queryRequest,
                ['statement_position' => $statementPosition],
            ),
            'execution_failed',
            true,
        );
    }

    public function scheduledBatchPreflightBlocked(QueryRequest $queryRequest): void
    {
        if (! $this->settings->notificationEventEnabled('execution_failed')) {
            return;
        }

        $recipients = $this->requestActors($queryRequest)
            ->merge($this->reviewersFor($queryRequest))
            ->merge($this->subscribersForQueryRequest($queryRequest))
            ->merge($this->operationalRecipients())
            ->unique('id')
            ->values();

        $this->notify(
            $recipients,
            $this->requestPayload(
                'query_request.preflight_blocked',
                'critical',
                'Scheduled batch blocked',
                "{$queryRequest->title} did not run because a critical preflight check failed. Review the blocked statements before dispatching it again.",
                $queryRequest,
            ),
            'execution_failed',
            true,
        );
    }

    public function sessionStarted(QuerySession $querySession): void
    {
        if (! $this->settings->notificationEventEnabled('query_access')) {
            return;
        }

        $querySession->loadMissing('queryRequest', 'user', 'databaseConnections');
        $queryRequest = $querySession->queryRequest;

        $this->notify(
            collect([$querySession->user])
                ->merge($this->subscribersForQueryRequest($queryRequest))
                ->unique('id')
                ->values(),
            [
                ...$this->requestPayload(
                    'query_session.started',
                    'success',
                    'Query access session started',
                    "{$queryRequest->title} is open until {$querySession->expires_at->format('H:i T')}.",
                    $queryRequest,
                    ['session_id' => $querySession->id],
                ),
                'url' => route('query-sessions.show', $querySession),
                'action_label' => 'Open session',
            ],
            'sessions',
        );
    }

    public function sessionExpired(QuerySession $querySession): void
    {
        if (! $this->settings->notificationEventEnabled('query_access')) {
            return;
        }

        $querySession->loadMissing('queryRequest', 'user');

        $this->notify(
            collect([$querySession->user])
                ->merge($this->subscribersForQueryRequest($querySession->queryRequest))
                ->merge($this->operationalRecipients())
                ->unique('id')
                ->values(),
            $this->requestPayload(
                'query_session.expired',
                'warning',
                'Query access session expired',
                "{$querySession->queryRequest->title} has reached the end of its approved access window.",
                $querySession->queryRequest,
                ['session_id' => $querySession->id],
            ),
            'sessions',
        );
    }

    public function connectionTestFailed(DatabaseConnection $databaseConnection, User $actor): void
    {
        if (! $this->settings->notificationEventEnabled('connection_failed')) {
            return;
        }

        $databaseConnection->loadMissing('createdBy');
        $recipients = collect([$databaseConnection->createdBy ?? $actor])
            ->merge($this->subscribersForDatabaseConnection($databaseConnection))
            ->merge($this->operationalRecipients())
            ->unique('id')
            ->values();

        $this->notify(
            $recipients,
            [
                'event' => 'database_connection.test_failed',
                'severity' => 'critical',
                'title' => 'Connection test failed',
                'message' => "{$databaseConnection->name} could not be reached during a connection test.",
                'action_label' => 'Open connection',
                'url' => route('connections.show', $databaseConnection),
                'connection_id' => $databaseConnection->id,
                'connection_count' => 1,
            ],
            'connection_failed',
            true,
        );
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @param  array{event:string,severity:'info'|'success'|'warning'|'critical',title:string,message:string,action_label:string,url:string,request_id?:int,connection_count?:int,statement_position?:int|null,session_id?:int,connection_id?:int}  $payload
     */
    private function notify(Collection $recipients, array $payload, string $emailPreference, bool $emailDefault = false): void
    {
        $sendDatabase = $this->settings->notificationsInAppEnabled();
        $sendEmail = $this->settings->notificationsEmailEnabled();

        if (! $sendDatabase && ! $sendEmail) {
            return;
        }

        $recipients
            ->filter(fn (User $user): bool => ! $user->isDisabled())
            ->each(function (User $user) use ($emailDefault, $emailPreference, $payload, $sendDatabase, $sendEmail): void {
                $shouldSendEmail = $sendEmail && $user->wantsNotificationEmail($emailPreference, $emailDefault);

                if (! $sendDatabase && ! $shouldSendEmail) {
                    return;
                }

                $user->notify(new OperationalNotification($payload, $sendDatabase, $shouldSendEmail));
            });
    }

    /**
     * @return Collection<int, User>
     */
    private function requestActors(QueryRequest $queryRequest): Collection
    {
        $queryRequest->loadMissing('requester', 'dispatchedBy');

        return collect([$queryRequest->requester, $queryRequest->dispatchedBy])
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function subscribersForQueryRequest(QueryRequest $queryRequest): Collection
    {
        $queryRequest->loadMissing('notificationSubscriptions.user');

        return $queryRequest->notificationSubscriptions
            ->map(fn ($subscription): ?User => $subscription->user)
            ->filter()
            ->filter(fn (User $user): bool => ! $user->isDisabled() && $user->can('view', $queryRequest))
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function subscribersForDatabaseConnection(DatabaseConnection $databaseConnection): Collection
    {
        $databaseConnection->loadMissing('notificationSubscriptions.user');

        return $databaseConnection->notificationSubscriptions
            ->map(fn ($subscription): ?User => $subscription->user)
            ->filter()
            ->filter(fn (User $user): bool => ! $user->isDisabled() && $user->can('view', $databaseConnection))
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function reviewersFor(QueryRequest $queryRequest): Collection
    {
        $connections = $this->targetConnections($queryRequest);

        return User::query()
            ->whereNull('disabled_at')
            ->with('roles.databasePermissions')
            ->get()
            ->filter(fn (User $user): bool => $user->id !== $queryRequest->requester_id
                && $connections->every(fn (DatabaseConnection $connection): bool => $this->canReviewConnection($user, $connection)))
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function operationalRecipients(): Collection
    {
        $recipients = User::query()
            ->whereNull('disabled_at')
            ->where('is_operational_alert_recipient', true)
            ->get();

        if ($recipients->isNotEmpty()) {
            return $recipients;
        }

        return User::query()
            ->whereNull('disabled_at')
            ->whereHas('roles', fn ($roles) => $roles->where('is_admin', true))
            ->get();
    }

    /**
     * @return Collection<int, DatabaseConnection>
     */
    private function targetConnections(QueryRequest $queryRequest): Collection
    {
        $queryRequest->loadMissing('databaseConnection', 'accessConnections', 'statements.databaseConnection');

        if ($queryRequest->request_kind->value === 'query_access' && $queryRequest->accessConnections->isNotEmpty()) {
            return $queryRequest->accessConnections;
        }

        $connections = $queryRequest->statements
            ->map(fn ($statement) => $statement->databaseConnection ?? $queryRequest->databaseConnection)
            ->filter()
            ->unique('id')
            ->values();

        return $connections->isNotEmpty()
            ? $connections
            : new Collection([$queryRequest->databaseConnection]);
    }

    private function canReviewConnection(User $user, DatabaseConnection $connection): bool
    {
        if ($user->roles->contains('is_admin', true)) {
            return true;
        }

        foreach ($user->roles as $role) {
            $permission = $role->databasePermissions
                ->firstWhere('database_connection_id', $connection->id);

            if ($permission !== null) {
                return $permission->can_review;
            }
        }

        return false;
    }

    /**
     * @param  array{statement_position?:int|null,session_id?:int}  $extra
     * @return array{event:string,severity:'info'|'success'|'warning'|'critical',title:string,message:string,action_label:string,url:string,request_id:int,connection_count:int,statement_position?:int|null,session_id?:int}
     */
    private function requestPayload(
        string $event,
        string $severity,
        string $title,
        string $message,
        QueryRequest $queryRequest,
        array $extra = [],
    ): array {
        return [
            'event' => $event,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'action_label' => 'Open request',
            'url' => route('query-requests.show', $queryRequest),
            'request_id' => $queryRequest->id,
            'connection_count' => $this->targetConnections($queryRequest)->count(),
            ...$extra,
        ];
    }
}
