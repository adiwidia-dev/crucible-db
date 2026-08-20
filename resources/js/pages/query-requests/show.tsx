import { Form, Head, Link, usePage, usePoll } from '@inertiajs/react';
import {
    Check,
    BellOff,
    BellRing,
    CircleCheck,
    CircleMinus,
    CircleStop,
    CircleX,
    ChevronDown,
    Clock3,
    Download,
    FileCode2,
    KeyRound,
    Pencil,
    RefreshCw,
    RotateCcw,
    Send,
    Trash2,
} from 'lucide-react';
import { Fragment, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { format } from 'sql-formatter';
import NotificationSubscriptionController from '@/actions/App/Http/Controllers/NotificationSubscriptionController';
import QueryExecutionExportController from '@/actions/App/Http/Controllers/QueryExecutionExportController';
import QueryRequestController from '@/actions/App/Http/Controllers/QueryRequestController';
import QueryReviewController from '@/actions/App/Http/Controllers/QueryReviewController';
import QuerySessionController from '@/actions/App/Http/Controllers/QuerySessionController';
import { PageHeader } from '@/components/crucible/page-header';
import { Pagination } from '@/components/crucible/pagination';
import { SqlEditor } from '@/components/crucible/sql-editor';
import { StatusBadge } from '@/components/crucible/status-badge';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    driverLabel,
    formatDate,
    queryRequestKindLabel,
    statusLabel,
} from '@/lib/crucible';
import type {
    ExecutionStatus,
    Paginated,
    QueryRequestStatus,
    QueryRequestKind,
    QueryType,
} from '@/lib/crucible';
import { index } from '@/routes/query-requests';
import { show as querySessionShow } from '@/routes/query-sessions';
import type { Auth } from '@/types';

type Execution = {
    id: number;
    statement_position: number | null;
    status: ExecutionStatus;
    query_type: QueryType;
    sql: string | null;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
    row_count: number | null;
    result_truncated: boolean;
    sample_rows: Array<Record<string, unknown>> | null;
    error_message: string | null;
    executor: string | null;
    connection: {
        id: number;
        name: string;
        driver: string;
    };
};

type QueryRequest = {
    id: number;
    title: string;
    description: string | null;
    sql: string | null;
    statements: Array<{
        id: number;
        position: number;
        sql: string;
        query_type: QueryType;
        connection: {
            id: number;
            name: string;
            driver: string;
        };
        execution: {
            status: ExecutionStatus;
            error_message: string | null;
        } | null;
        execution_state: ExecutionStatus | 'skipped' | null;
    }>;
    status: QueryRequestStatus;
    query_type: QueryType;
    request_kind: QueryRequestKind;
    requested_access_mode: 'read' | 'write' | null;
    requires_approval: boolean;
    scheduled_at: string | null;
    approved_after_schedule: boolean;
    access_duration_minutes: number | null;
    created_at: string | null;
    approved_at: string | null;
    dispatched_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    last_error: string | null;
    result_summary: Record<string, unknown> | null;
    preflight: {
        status:
            'not_run' | 'passed' | 'passed_with_warnings' | 'blocked' | 'stale';
        checked_at: string | null;
        blocker_count: number;
        warning_count: number;
        statements: Array<{
            position: number;
            connection_id: number | null;
            connection_name: string | null;
            query_type: QueryType | null;
            status: 'passed' | 'warning' | 'blocked';
            messages: Array<{
                level: 'warning' | 'blocked';
                code: string;
                message: string;
            }>;
        }>;
    };
    requester: string;
    approved_by: string | null;
    cancelled_by: string | null;
    retry_of: {
        id: number;
        title: string;
    } | null;
    retries: Array<{
        id: number;
        title: string;
        status: QueryRequestStatus;
    }>;
    connection: {
        id: number;
        name: string;
        driver: string;
    };
    access_connections: Array<{
        id: number;
        name: string;
        driver: string;
    }>;
    reviews: Array<{
        id: number;
        decision: string;
        comment: string | null;
        reviewer: string;
        created_at: string | null;
    }>;
    executions: Paginated<Execution>;
    sessions: Array<{
        id: number;
        started_at: string | null;
        expires_at: string | null;
        ended_at: string | null;
    }>;
    active_session: {
        id: number;
        expires_at: string | null;
    } | null;
};

type Props = {
    query_request: QueryRequest;
    can_review: boolean;
    can_update: boolean;
    can_dispatch: boolean;
    can_cancel: boolean;
    can_retry: boolean;
    retry_strategy:
        'resume_read_only' | 'create_retry_request' | 'renew_access' | null;
    can_start_session: boolean;
    can_delete: boolean;
    is_subscribed: boolean;
};

function remainingSeconds(expiresAt: string | null): number {
    if (expiresAt === null) {
        return 0;
    }

    return Math.max(
        0,
        Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000),
    );
}

function remainingLabel(seconds: number): string {
    const minutes = Math.floor(seconds / 60);
    const remainder = seconds % 60;

    return `${minutes}:${remainder.toString().padStart(2, '0')}`;
}

function SampleRows({ rows }: { rows: Array<Record<string, unknown>> }) {
    const columns = Array.from(
        rows.reduce((set, row) => {
            Object.keys(row).forEach((key) => set.add(key));

            return set;
        }, new Set<string>()),
    );

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground uppercase">
                        {columns.map((column, index) => (
                            <th
                                key={column}
                                className={`py-3 pr-4 font-medium ${
                                    index === 0 ? 'pl-4 sm:pl-6' : ''
                                }`}
                            >
                                {column}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => (
                        <tr
                            key={index}
                            className="border-b transition-colors last:border-0 hover:bg-accent/40"
                        >
                            {columns.map((column, columnIndex) => (
                                <td
                                    key={column}
                                    className={`max-w-80 truncate py-3.5 pr-4 font-mono text-xs ${
                                        columnIndex === 0 ? 'pl-4 sm:pl-6' : ''
                                    }`}
                                >
                                    {String(row[column] ?? '')}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function ExecutionResult({ execution }: { execution: Execution }) {
    const rows = execution.sample_rows ?? [];

    if (execution.error_message) {
        return (
            <div className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900/70 dark:bg-red-950/40 dark:text-red-300">
                {execution.error_message}
            </div>
        );
    }

    if (rows.length === 0) {
        return (
            <div className="rounded-md border bg-background px-3 py-6 text-center text-sm text-muted-foreground">
                No sample rows recorded for this execution.
            </div>
        );
    }

    return (
        <div className="rounded-md border bg-background">
            <div className="flex items-center justify-end border-b px-3 py-2">
                <Button variant="outline" size="sm" asChild>
                    <a href={QueryExecutionExportController.url(execution.id)}>
                        <Download />
                        Export CSV
                    </a>
                </Button>
            </div>
            <div className="max-h-80 overflow-auto">
                <SampleRows rows={rows} />
            </div>
        </div>
    );
}

export default function QueryRequestShow({
    query_request,
    can_review,
    can_update,
    can_dispatch,
    can_cancel,
    can_retry,
    retry_strategy,
    can_start_session,
    can_delete,
    is_subscribed,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const userTimezone = auth.user.timezone ?? 'UTC';
    const isQueryAccess = query_request.request_kind === 'query_access';
    const isActiveQueryAccess =
        isQueryAccess && query_request.status === 'running';
    const activeSessionExpiresAt =
        query_request.active_session?.expires_at ?? null;
    const latestSession = query_request.sessions[0] ?? null;
    const latestSessionEndedAt = latestSession?.ended_at ?? null;
    const latestSessionExpired =
        latestSessionEndedAt !== null &&
        latestSession?.expires_at !== null &&
        Date.parse(latestSessionEndedAt) >=
            Date.parse(latestSession.expires_at);
    const [secondsRemaining, setSecondsRemaining] = useState(() =>
        remainingSeconds(activeSessionExpiresAt),
    );
    const lastExecution = query_request.executions.data[0];
    const [expandedExecutionIds, setExpandedExecutionIds] = useState<number[]>(
        [],
    );
    const [isPreflightExpanded, setIsPreflightExpanded] = useState(false);
    const executionToastStorageKey = `query-request:${query_request.id}:awaiting-execution`;
    const [awaitingExecution, setAwaitingExecution] = useState(
        () =>
            typeof window !== 'undefined' &&
            window.sessionStorage.getItem(executionToastStorageKey) === '1',
    );
    const shouldPollExecution =
        query_request.request_kind === 'single_execution' &&
        query_request.completed_at === null &&
        (query_request.dispatched_at !== null || awaitingExecution);
    const { start: startPolling, stop: stopPolling } = usePoll(
        1500,
        {
            only: ['query_request', 'can_dispatch', 'can_cancel', 'can_retry'],
        },
        {
            autoStart: false,
            mode: 'rest',
        },
    );
    const formattedStatements = useMemo(
        () =>
            (query_request.statements.length > 0
                ? query_request.statements
                : [
                      {
                          id: 0,
                          position: 1,
                          sql: query_request.sql ?? '',
                          query_type: query_request.query_type,
                          connection: query_request.connection,
                          execution: null,
                          execution_state: null,
                      },
                  ]
            ).map((statement) => {
                try {
                    return {
                        ...statement,
                        sql: format(statement.sql, {
                            language:
                                statement.connection.driver === 'mysql'
                                    ? 'mysql'
                                    : 'postgresql',
                            keywordCase: 'upper',
                        }),
                    };
                } catch {
                    return statement;
                }
            }),
        [
            query_request.connection,
            query_request.query_type,
            query_request.sql,
            query_request.statements,
        ],
    );
    const hasStatementLevelFailure = query_request.statements.some(
        (statement) => statement.execution_state === 'failed',
    );
    const preflightByPosition = useMemo(
        () =>
            new Map(
                query_request.preflight.statements.map((statement) => [
                    statement.position,
                    statement,
                ]),
            ),
        [query_request.preflight.statements],
    );
    const preflightLabel = {
        not_run: 'Recheck required',
        passed: 'Ready',
        passed_with_warnings: 'Ready with warnings',
        blocked: 'Blocked',
        stale: 'Recheck required',
    }[query_request.preflight.status];
    const preflightFindings = useMemo(
        () =>
            formattedStatements.flatMap((statement) => {
                const preflight = preflightByPosition.get(statement.position);

                return preflight?.messages.length
                    ? [{ statement, preflight }]
                    : [];
            }),
        [formattedStatements, preflightByPosition],
    );
    const approvedAfterSchedule = query_request.approved_after_schedule;
    const scheduledAtLabel = formatDate(
        query_request.scheduled_at,
        userTimezone,
    );
    const targetConnections = useMemo(() => {
        const connections =
            query_request.request_kind === 'query_access' &&
            query_request.access_connections.length > 0
                ? query_request.access_connections
                : formattedStatements.map((statement) => statement.connection);

        return Array.from(
            new Map(
                connections.map((connection) => [connection.id, connection]),
            ).values(),
        );
    }, [
        formattedStatements,
        query_request.access_connections,
        query_request.request_kind,
    ]);
    const actionSummary =
        query_request.status === 'cancelled' ||
        (can_retry && retry_strategy === 'renew_access')
            ? null
            : can_review
              ? 'Review is required before this request can proceed.'
              : approvedAfterSchedule
                ? `Scheduled for ${scheduledAtLabel}. It was approved after the planned time and will not run automatically.`
                : query_request.active_session
                  ? 'A query-access session is active.'
                  : can_start_session
                    ? 'This approved request is ready to start a session.'
                    : can_dispatch
                      ? 'This approved batch is ready for ordered execution.'
                      : can_retry && retry_strategy === 'resume_read_only'
                        ? 'Execution stopped. Retry from the failed read-only statement.'
                        : can_retry
                          ? 'Create a linked retry request for fresh approval.'
                          : query_request.status === 'failed'
                            ? 'Execution stopped. Review the failed statement below.'
                            : query_request.status === 'completed'
                              ? 'This request has completed.'
                              : 'No action is currently available.';

    function toggleExecutionSql(executionId: number): void {
        setExpandedExecutionIds((current) =>
            current.includes(executionId)
                ? current.filter((id) => id !== executionId)
                : [...current, executionId],
        );
    }

    useEffect(() => {
        if (shouldPollExecution) {
            startPolling();

            return () => stopPolling();
        }

        stopPolling();
    }, [shouldPollExecution, startPolling, stopPolling]);

    useEffect(() => {
        const updateRemainingTime = (): void => {
            setSecondsRemaining(remainingSeconds(activeSessionExpiresAt));
        };

        updateRemainingTime();

        if (activeSessionExpiresAt === null) {
            return;
        }

        const interval = window.setInterval(updateRemainingTime, 1000);

        return () => window.clearInterval(interval);
    }, [activeSessionExpiresAt]);

    useEffect(() => {
        if (!awaitingExecution || query_request.completed_at === null) {
            return;
        }

        window.sessionStorage.removeItem(executionToastStorageKey);
        const resetAwaitingExecution = window.setTimeout(() => {
            setAwaitingExecution(false);
        }, 0);

        if (lastExecution?.status === 'succeeded') {
            toast.success(
                `Query executed${lastExecution.executor ? ` by ${lastExecution.executor}` : ''}.`,
            );
        }

        if (
            lastExecution?.status === 'failed' ||
            query_request.status === 'failed'
        ) {
            toast.error('Query execution failed. Check execution history.');
        }

        return () => window.clearTimeout(resetAwaitingExecution);
    }, [
        awaitingExecution,
        executionToastStorageKey,
        lastExecution,
        query_request.completed_at,
        query_request.status,
    ]);

    return (
        <>
            <Head title={query_request.title} />

            <div className="crucible-page">
                <PageHeader
                    icon={FileCode2}
                    title={query_request.title}
                    description={`Request #${query_request.id}`}
                />

                <section
                    aria-label="Request status and actions"
                    className="sticky top-2 z-20 border-y bg-card px-4 py-3 sm:top-3 sm:rounded-lg sm:border sm:px-5"
                >
                    <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                                <StatusBadge value={query_request.status} />
                                {approvedAfterSchedule && (
                                    <StatusBadge
                                        value="schedule_missed"
                                        label="Schedule missed"
                                    />
                                )}
                                {actionSummary && (
                                    <span className="text-sm font-medium">
                                        {actionSummary}
                                    </span>
                                )}
                                {is_subscribed ? (
                                    <Form
                                        {...NotificationSubscriptionController.destroyQueryRequest.form(
                                            query_request.id,
                                        )}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="h-7 px-2 text-xs text-muted-foreground"
                                                disabled={processing}
                                            >
                                                <BellOff />
                                                Watching
                                            </Button>
                                        )}
                                    </Form>
                                ) : (
                                    <Form
                                        {...NotificationSubscriptionController.storeQueryRequest.form(
                                            query_request.id,
                                        )}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="h-7 px-2 text-xs text-muted-foreground"
                                                disabled={processing}
                                            >
                                                <BellRing />
                                                Watch updates
                                            </Button>
                                        )}
                                    </Form>
                                )}
                            </div>
                            <dl className="mt-2 grid grid-cols-2 gap-x-5 gap-y-1 text-xs text-muted-foreground sm:flex sm:flex-wrap sm:gap-x-5">
                                <div className="flex min-w-0 gap-1.5">
                                    <dt>Approval</dt>
                                    <dd className="truncate font-medium text-foreground">
                                        {query_request.requires_approval
                                            ? query_request.approved_by
                                                ? 'Approved'
                                                : 'Required'
                                            : 'Not required'}
                                    </dd>
                                </div>
                                <div className="flex min-w-0 gap-1.5">
                                    <dt>
                                        {query_request.request_kind ===
                                        'query_access'
                                            ? 'Window'
                                            : 'Schedule'}
                                    </dt>
                                    <dd className="truncate font-medium text-foreground">
                                        {query_request.request_kind ===
                                        'query_access'
                                            ? `${query_request.access_duration_minutes ?? 60} minutes`
                                            : scheduledAtLabel}
                                    </dd>
                                </div>
                                {query_request.request_kind ===
                                    'query_access' && (
                                    <div className="flex min-w-0 gap-1.5">
                                        <dt>Access</dt>
                                        <dd className="truncate font-medium text-foreground">
                                            {query_request.requested_access_mode ===
                                            'write'
                                                ? 'Read + write'
                                                : 'Read-only'}
                                        </dd>
                                    </div>
                                )}
                                {isQueryAccess && (
                                    <div className="flex min-w-0 gap-1.5">
                                        <dt>
                                            {isActiveQueryAccess
                                                ? 'Remaining'
                                                : 'Session'}
                                        </dt>
                                        <dd className="inline-flex items-center gap-1 truncate font-medium text-foreground">
                                            <Clock3 className="size-3.5 text-muted-foreground" />
                                            {isActiveQueryAccess
                                                ? secondsRemaining > 0
                                                    ? remainingLabel(
                                                          secondsRemaining,
                                                      )
                                                    : 'Expired'
                                                : latestSessionEndedAt
                                                  ? latestSessionExpired
                                                      ? 'Expired'
                                                      : 'Ended'
                                                  : 'Not started'}
                                        </dd>
                                    </div>
                                )}
                                <div className="flex min-w-0 gap-1.5">
                                    <dt>Targets</dt>
                                    <dd
                                        className="truncate font-medium text-foreground"
                                        title={targetConnections
                                            .map(
                                                (connection) => connection.name,
                                            )
                                            .join(', ')}
                                    >
                                        {targetConnections.length}{' '}
                                        {targetConnections.length === 1
                                            ? 'connection'
                                            : 'connections'}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <div className="flex flex-wrap items-center gap-2 xl:flex-nowrap xl:justify-end">
                            {can_review && (
                                <Button asChild size="sm">
                                    <a href="#review-request">
                                        <Check />
                                        Review request
                                    </a>
                                </Button>
                            )}
                            {query_request.active_session ? (
                                <Button size="sm" asChild>
                                    <Link
                                        href={querySessionShow(
                                            query_request.active_session.id,
                                        )}
                                    >
                                        <RotateCcw />
                                        Resume Session
                                    </Link>
                                </Button>
                            ) : (
                                can_start_session && (
                                    <Form
                                        {...QuerySessionController.store.form(
                                            query_request.id,
                                        )}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                size="sm"
                                                disabled={processing}
                                            >
                                                <KeyRound />
                                                Start Session
                                            </Button>
                                        )}
                                    </Form>
                                )
                            )}
                            {can_dispatch && (
                                <Form
                                    {...QueryRequestController.dispatch.form(
                                        query_request.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing, recentlySuccessful }) => (
                                        <Button
                                            size="sm"
                                            disabled={
                                                processing || recentlySuccessful
                                            }
                                            onClick={() => {
                                                window.sessionStorage.setItem(
                                                    executionToastStorageKey,
                                                    '1',
                                                );
                                                setAwaitingExecution(true);
                                            }}
                                        >
                                            <Send />
                                            {processing
                                                ? 'Dispatching...'
                                                : recentlySuccessful
                                                  ? 'Dispatched'
                                                  : approvedAfterSchedule
                                                    ? 'Run now'
                                                    : 'Execute batch'}
                                        </Button>
                                    )}
                                </Form>
                            )}
                            {can_retry && retry_strategy && (
                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button variant="outline" size="sm">
                                            <RefreshCw />
                                            {retry_strategy ===
                                            'resume_read_only'
                                                ? 'Retry remaining'
                                                : retry_strategy ===
                                                    'renew_access'
                                                  ? 'Request access again'
                                                  : 'Create retry'}
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>
                                                {retry_strategy ===
                                                'resume_read_only'
                                                    ? 'Retry remaining read-only statements?'
                                                    : retry_strategy ===
                                                        'renew_access'
                                                      ? 'Request query access again?'
                                                      : 'Create a retry request?'}
                                            </DialogTitle>
                                            <DialogDescription>
                                                {retry_strategy ===
                                                'resume_read_only'
                                                    ? 'The batch will resume at the failed statement. Previously successful statements will not run again.'
                                                    : retry_strategy ===
                                                        'renew_access'
                                                      ? 'This creates a linked request with the same selected connections, session level, and duration. Approval is evaluated against your current connection policy.'
                                                      : 'This copies the failed statement and all remaining statements into a linked request. It requires fresh approval before execution.'}
                                            </DialogDescription>
                                        </DialogHeader>
                                        <DialogFooter>
                                            <DialogClose asChild>
                                                <Button variant="outline">
                                                    Cancel
                                                </Button>
                                            </DialogClose>
                                            <Form
                                                {...QueryRequestController.retry.form(
                                                    query_request.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        disabled={processing}
                                                        onClick={() => {
                                                            if (
                                                                retry_strategy ===
                                                                'resume_read_only'
                                                            ) {
                                                                window.sessionStorage.setItem(
                                                                    executionToastStorageKey,
                                                                    '1',
                                                                );
                                                                setAwaitingExecution(
                                                                    true,
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        <RefreshCw />
                                                        {processing
                                                            ? 'Working...'
                                                            : retry_strategy ===
                                                                'resume_read_only'
                                                              ? 'Retry remaining'
                                                              : retry_strategy ===
                                                                  'renew_access'
                                                                ? 'Create access request'
                                                                : 'Create retry request'}
                                                    </Button>
                                                )}
                                            </Form>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            )}
                            {can_cancel && (
                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button
                                            variant={
                                                query_request.status ===
                                                'running'
                                                    ? 'destructive'
                                                    : 'outline'
                                            }
                                            size="sm"
                                        >
                                            <CircleStop />
                                            {isActiveQueryAccess
                                                ? 'End session'
                                                : query_request.status ===
                                                    'running'
                                                  ? 'Request stop'
                                                  : 'Cancel request'}
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>
                                                {isActiveQueryAccess
                                                    ? 'End this access session?'
                                                    : query_request.status ===
                                                        'running'
                                                      ? 'Stop this deployment batch?'
                                                      : 'Cancel this query request?'}
                                            </DialogTitle>
                                            <DialogDescription>
                                                {isActiveQueryAccess
                                                    ? 'This ends every active session for this request now. The request remains in audit history as cancelled.'
                                                    : query_request.status ===
                                                        'running'
                                                      ? 'The currently running statement may finish, but no later statements will start. The execution record will be preserved.'
                                                      : isQueryAccess
                                                        ? 'This revokes any active session and keeps the request record for audit history.'
                                                        : 'This keeps the request and its approval history for audit purposes.'}
                                            </DialogDescription>
                                        </DialogHeader>
                                        <Form
                                            {...QueryRequestController.cancel.form(
                                                query_request.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                            className="grid gap-2"
                                        >
                                            {({ processing, errors }) => (
                                                <>
                                                    <Label htmlFor="cancellation-reason">
                                                        {isActiveQueryAccess
                                                            ? 'Reason for ending access'
                                                            : 'Reason'}
                                                    </Label>
                                                    <textarea
                                                        id="cancellation-reason"
                                                        name="reason"
                                                        rows={3}
                                                        required
                                                        className="min-h-24 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                                        placeholder={
                                                            isActiveQueryAccess
                                                                ? 'Why should this access session end?'
                                                                : 'Why is this request being cancelled?'
                                                        }
                                                    />
                                                    <InputError
                                                        message={errors.reason}
                                                    />
                                                    <DialogFooter className="mt-2">
                                                        <DialogClose asChild>
                                                            <Button variant="outline">
                                                                {isActiveQueryAccess
                                                                    ? 'Keep session'
                                                                    : 'Keep request'}
                                                            </Button>
                                                        </DialogClose>
                                                        <Button
                                                            variant="destructive"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            <CircleStop />
                                                            {processing
                                                                ? isActiveQueryAccess
                                                                    ? 'Ending session...'
                                                                    : 'Cancelling...'
                                                                : isActiveQueryAccess
                                                                  ? 'End session'
                                                                  : 'Cancel request'}
                                                        </Button>
                                                    </DialogFooter>
                                                </>
                                            )}
                                        </Form>
                                    </DialogContent>
                                </Dialog>
                            )}
                            {can_update && !isQueryAccess && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={QueryRequestController.edit(
                                            query_request.id,
                                        )}
                                    >
                                        <Pencil />
                                        Edit
                                    </Link>
                                </Button>
                            )}
                            {can_delete && (
                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                            aria-label="Delete request"
                                        >
                                            <Trash2 />
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>
                                                Delete query access request?
                                            </DialogTitle>
                                            <DialogDescription>
                                                This removes the request and its
                                                session records from the active
                                                workspace. A detailed deletion
                                                audit event will remain.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <DialogFooter>
                                            <DialogClose asChild>
                                                <Button variant="outline">
                                                    Cancel
                                                </Button>
                                            </DialogClose>
                                            <Form
                                                {...QueryRequestController.destroy.form(
                                                    query_request.id,
                                                )}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        variant="destructive"
                                                        disabled={processing}
                                                    >
                                                        <Trash2 />
                                                        Yes, delete
                                                    </Button>
                                                )}
                                            </Form>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            )}
                        </div>
                    </div>
                </section>

                <section
                    aria-labelledby="request-record-title"
                    className={`overflow-hidden border-y bg-card ${
                        query_request.request_kind === 'single_execution'
                            ? 'border-b-0 sm:rounded-t-lg sm:rounded-b-none sm:border'
                            : 'sm:rounded-lg sm:border'
                    }`}
                >
                    <div className="flex items-center justify-between gap-3 border-b px-4 py-3 sm:px-5">
                        <div>
                            <h2
                                id="request-record-title"
                                className="text-sm font-semibold"
                            >
                                Request overview
                            </h2>
                        </div>
                        <StatusBadge
                            value={query_request.request_kind}
                            label={queryRequestKindLabel(
                                query_request.request_kind,
                            )}
                        />
                    </div>
                    <div className="px-4 py-3 sm:px-5">
                        <dl className="grid gap-x-5 gap-y-3 text-sm min-[420px]:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                            <div className="min-w-0">
                                <dt className="text-xs text-muted-foreground">
                                    Requester
                                </dt>
                                <dd className="mt-1 truncate font-medium">
                                    {query_request.requester}
                                </dd>
                            </div>
                            <div className="min-w-0">
                                <dt className="text-xs text-muted-foreground">
                                    Created
                                </dt>
                                <dd className="mt-1 font-mono text-xs font-medium">
                                    {formatDate(
                                        query_request.created_at,
                                        userTimezone,
                                    )}
                                </dd>
                            </div>
                            <div className="min-w-0">
                                <dt className="text-xs text-muted-foreground">
                                    Approval decision
                                </dt>
                                <dd className="mt-1 truncate font-medium">
                                    {query_request.approved_by
                                        ? `Approved by ${query_request.approved_by}`
                                        : query_request.requires_approval
                                          ? 'Awaiting decision'
                                          : 'Approval not required'}
                                </dd>
                                {query_request.approved_at && (
                                    <span className="mt-1 block font-mono text-xs text-muted-foreground">
                                        {formatDate(
                                            query_request.approved_at,
                                            userTimezone,
                                        )}
                                    </span>
                                )}
                            </div>
                            <div className="min-w-0">
                                <dt className="text-xs text-muted-foreground">
                                    {query_request.status === 'cancelled'
                                        ? 'Cancelled'
                                        : 'Finished'}
                                </dt>
                                <dd className="mt-1 font-mono text-xs font-medium">
                                    {formatDate(
                                        query_request.status === 'cancelled'
                                            ? query_request.cancelled_at
                                            : query_request.completed_at,
                                        userTimezone,
                                    )}
                                </dd>
                            </div>
                            <div className="min-w-0">
                                <dt className="text-xs text-muted-foreground">
                                    Target connections
                                </dt>
                                <dd className="mt-1 flex flex-wrap gap-1.5">
                                    {targetConnections.map((connection) => (
                                        <span
                                            key={connection.id}
                                            className="inline-flex items-center gap-1 rounded-md border bg-background px-1.5 py-0.5 text-xs"
                                        >
                                            <span className="max-w-32 truncate font-medium">
                                                {connection.name}
                                            </span>
                                            <StatusBadge
                                                value={connection.driver}
                                                label={driverLabel(
                                                    connection.driver,
                                                )}
                                            />
                                        </span>
                                    ))}
                                </dd>
                            </div>
                        </dl>

                        {query_request.description && (
                            <div className="mt-3 border-t pt-3">
                                <p className="text-xs text-muted-foreground">
                                    Purpose and context
                                </p>
                                <p className="mt-1 text-sm leading-6 text-foreground/80">
                                    {query_request.description}
                                </p>
                            </div>
                        )}

                        {query_request.status === 'cancelled' && (
                            <div className="mt-3 border-t pt-3 text-sm">
                                <p className="text-xs text-muted-foreground">
                                    Cancellation
                                </p>
                                <p className="mt-1 font-medium text-foreground">
                                    {query_request.cancelled_by
                                        ? `Cancelled by ${query_request.cancelled_by}`
                                        : 'Cancelled'}
                                </p>
                                {query_request.cancellation_reason && (
                                    <p className="mt-1 text-muted-foreground">
                                        {query_request.cancellation_reason}
                                    </p>
                                )}
                            </div>
                        )}

                        {(query_request.retry_of ||
                            query_request.retries.length > 0) && (
                            <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-t pt-3 text-sm">
                                <span className="text-xs text-muted-foreground">
                                    Related requests
                                </span>
                                {query_request.retry_of && (
                                    <span className="text-muted-foreground">
                                        Retry of{' '}
                                        <Link
                                            href={QueryRequestController.show(
                                                query_request.retry_of.id,
                                            )}
                                            className="font-medium text-primary hover:underline"
                                        >
                                            #{query_request.retry_of.id}:{' '}
                                            {query_request.retry_of.title}
                                        </Link>
                                    </span>
                                )}
                                {query_request.retries.map((retry) => (
                                    <span
                                        key={retry.id}
                                        className="text-muted-foreground"
                                    >
                                        Retry{' '}
                                        <Link
                                            href={QueryRequestController.show(
                                                retry.id,
                                            )}
                                            className="font-medium text-primary hover:underline"
                                        >
                                            #{retry.id}: {retry.title}
                                        </Link>{' '}
                                        <StatusBadge value={retry.status} />
                                    </span>
                                ))}
                            </div>
                        )}

                        {query_request.last_error &&
                            !hasStatementLevelFailure && (
                                <div className="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900/70 dark:bg-red-950/40 dark:text-red-300">
                                    {query_request.last_error}
                                </div>
                            )}
                    </div>
                </section>

                {query_request.request_kind === 'single_execution' && (
                    <section
                        aria-labelledby="preflight-title"
                        className="-mt-6 overflow-hidden border-y bg-card sm:rounded-b-lg sm:border"
                    >
                        <div className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                            <div className="min-w-0">
                                <h2
                                    id="preflight-title"
                                    className="text-sm font-semibold"
                                >
                                    Execution preflight
                                </h2>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {formattedStatements.length} statement
                                    {formattedStatements.length === 1
                                        ? ''
                                        : 's'}{' '}
                                    checked. Critical checks run again before
                                    execution.
                                </p>
                            </div>
                            <span
                                className={`w-fit rounded-md border px-2 py-1 text-xs font-medium ${
                                    query_request.preflight.status === 'blocked'
                                        ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/70 dark:bg-red-950/40 dark:text-red-300'
                                        : query_request.preflight.status ===
                                            'passed_with_warnings'
                                          ? 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/70 dark:bg-amber-950/40 dark:text-amber-200'
                                          : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/70 dark:bg-emerald-950/40 dark:text-emerald-300'
                                }`}
                            >
                                {preflightLabel}
                                {query_request.preflight.warning_count > 0 &&
                                    ` · ${query_request.preflight.warning_count} warning${query_request.preflight.warning_count === 1 ? '' : 's'}`}
                            </span>
                        </div>
                        {query_request.preflight.status === 'not_run' ||
                        query_request.preflight.status === 'stale' ? (
                            <div className="border-t px-4 py-3 text-sm text-muted-foreground sm:px-5">
                                The batch will be checked when it is reviewed or
                                dispatched.
                            </div>
                        ) : (
                            <div className="border-t">
                                {preflightFindings.length > 0 ? (
                                    <div className="divide-y">
                                        {preflightFindings.map(
                                            ({ statement, preflight }) => (
                                                <div
                                                    key={statement.position}
                                                    className="flex items-start gap-2 px-4 py-2.5 sm:px-5"
                                                >
                                                    <span className="shrink-0 text-xs font-medium text-foreground">
                                                        Statement{' '}
                                                        {statement.position}
                                                    </span>
                                                    <ul className="min-w-0 flex-1">
                                                        {preflight.messages.map(
                                                            (message) => (
                                                                <li
                                                                    key={
                                                                        message.code
                                                                    }
                                                                    className={`flex gap-1.5 text-xs ${
                                                                        message.level ===
                                                                        'blocked'
                                                                            ? 'text-red-700 dark:text-red-300'
                                                                            : 'text-amber-800 dark:text-amber-200'
                                                                    }`}
                                                                >
                                                                    {message.level ===
                                                                    'blocked' ? (
                                                                        <CircleX className="mt-0.5 size-3.5 shrink-0" />
                                                                    ) : (
                                                                        <CircleMinus className="mt-0.5 size-3.5 shrink-0" />
                                                                    )}
                                                                    {
                                                                        message.message
                                                                    }
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                    <span className="hidden shrink-0 text-xs text-muted-foreground sm:block">
                                                        {
                                                            statement.connection
                                                                .name
                                                        }
                                                    </span>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                ) : (
                                    <div className="flex items-center gap-2 px-4 py-3 text-sm text-emerald-700 sm:px-5 dark:text-emerald-300">
                                        <CircleCheck className="size-4 shrink-0" />
                                        All statements are ready to execute.
                                    </div>
                                )}

                                <Collapsible
                                    open={isPreflightExpanded}
                                    onOpenChange={setIsPreflightExpanded}
                                >
                                    <CollapsibleTrigger asChild>
                                        <button
                                            type="button"
                                            className="flex w-full items-center justify-between border-t px-4 py-2.5 text-left text-xs font-medium text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:px-5"
                                            aria-label={`${isPreflightExpanded ? 'Hide' : 'Show'} all statement preflight checks`}
                                        >
                                            <span>
                                                {isPreflightExpanded
                                                    ? 'Hide full checklist'
                                                    : `View full checklist (${formattedStatements.length})`}
                                            </span>
                                            <ChevronDown
                                                className={`size-4 transition-transform duration-150 motion-reduce:transition-none ${
                                                    isPreflightExpanded
                                                        ? 'rotate-180'
                                                        : ''
                                                }`}
                                            />
                                        </button>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <div className="divide-y border-t">
                                            {formattedStatements.map(
                                                (statement) => {
                                                    const preflight =
                                                        preflightByPosition.get(
                                                            statement.position,
                                                        );

                                                    return (
                                                        <div
                                                            key={
                                                                statement.position
                                                            }
                                                            className="flex items-center justify-between gap-3 px-4 py-2.5 text-xs sm:px-5"
                                                        >
                                                            <div className="flex min-w-0 items-center gap-2">
                                                                {preflight?.status ===
                                                                'blocked' ? (
                                                                    <CircleX className="size-3.5 shrink-0 text-red-700 dark:text-red-300" />
                                                                ) : preflight?.status ===
                                                                  'warning' ? (
                                                                    <CircleMinus className="size-3.5 shrink-0 text-amber-800 dark:text-amber-200" />
                                                                ) : (
                                                                    <CircleCheck className="size-3.5 shrink-0 text-emerald-700 dark:text-emerald-300" />
                                                                )}
                                                                <span className="truncate font-medium">
                                                                    Statement{' '}
                                                                    {
                                                                        statement.position
                                                                    }
                                                                </span>
                                                            </div>
                                                            <span className="truncate text-muted-foreground">
                                                                {
                                                                    statement
                                                                        .connection
                                                                        .name
                                                                }
                                                            </span>
                                                        </div>
                                                    );
                                                },
                                            )}
                                        </div>
                                    </CollapsibleContent>
                                </Collapsible>
                            </div>
                        )}
                    </section>
                )}

                {query_request.request_kind === 'single_execution' ? (
                    <section
                        aria-labelledby="sql-batch-title"
                        className="overflow-hidden border-y bg-card sm:rounded-lg sm:border"
                    >
                        <div className="flex flex-col gap-1 border-b px-4 py-3 sm:px-5">
                            <h2
                                id="sql-batch-title"
                                className="text-sm font-semibold"
                            >
                                SQL Batch ({formattedStatements.length}{' '}
                                {formattedStatements.length === 1
                                    ? 'statement'
                                    : 'statements'}
                                )
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Statements execute in this order and stop at the
                                first failure.
                            </p>
                        </div>
                        <div className="divide-y">
                            {formattedStatements.map((statement) => {
                                const isSkipped =
                                    statement.execution_state === 'skipped';
                                const hasFinishedExecution =
                                    statement.execution_state === 'succeeded' ||
                                    statement.execution_state === 'failed' ||
                                    isSkipped;

                                return (
                                    <div
                                        key={statement.id || statement.position}
                                        className="bg-background"
                                    >
                                        <div className="flex h-9 items-center justify-between gap-2 border-b bg-muted/30 px-4 text-xs text-muted-foreground sm:px-5">
                                            <span className="flex items-center gap-2">
                                                {statement.execution_state ===
                                                'succeeded' ? (
                                                    <CircleCheck className="size-3.5 text-emerald-600 dark:text-emerald-400" />
                                                ) : statement.execution_state ===
                                                  'failed' ? (
                                                    <CircleX className="size-3.5 text-red-600 dark:text-red-400" />
                                                ) : isSkipped ? (
                                                    <CircleMinus className="size-3.5 text-muted-foreground" />
                                                ) : (
                                                    <FileCode2 className="size-3.5" />
                                                )}
                                                Statement {statement.position}
                                                {isSkipped ? (
                                                    <span className="text-muted-foreground/75">
                                                        Skipped
                                                    </span>
                                                ) : hasFinishedExecution ? (
                                                    <span className="text-muted-foreground/75">
                                                        Locked
                                                    </span>
                                                ) : null}
                                            </span>
                                            <span className="flex items-center gap-2">
                                                <span className="max-w-48 truncate font-medium text-foreground">
                                                    {statement.connection.name}
                                                </span>
                                                <StatusBadge
                                                    value={statement.query_type}
                                                />
                                            </span>
                                        </div>
                                        <div
                                            aria-disabled={hasFinishedExecution}
                                            className={
                                                hasFinishedExecution
                                                    ? 'bg-muted/20 opacity-75'
                                                    : undefined
                                            }
                                        >
                                            <SqlEditor
                                                value={statement.sql}
                                                onChange={() => undefined}
                                                driver={
                                                    statement.connection.driver
                                                }
                                                readOnly
                                                minHeight="8rem"
                                            />
                                        </div>
                                        {statement.execution?.status ===
                                            'failed' &&
                                            statement.execution
                                                .error_message && (
                                                <div className="flex gap-2 border-t bg-red-50 px-4 py-2 text-xs text-red-800 sm:px-5 dark:bg-red-950/30 dark:text-red-300">
                                                    <CircleX className="mt-0.5 size-3.5 shrink-0" />
                                                    <span>
                                                        {
                                                            statement.execution
                                                                .error_message
                                                        }
                                                    </span>
                                                </div>
                                            )}
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                ) : (
                    <section
                        aria-labelledby="access-sessions-title"
                        className="overflow-hidden border-y bg-card sm:rounded-lg sm:border"
                    >
                        <div className="border-b px-4 py-3 sm:px-5">
                            <h2
                                id="access-sessions-title"
                                className="text-sm font-semibold"
                            >
                                Access sessions
                            </h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Time-boxed database browser sessions started
                                from this request.
                            </p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[560px] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground uppercase">
                                        <th className="py-3 pr-4 pl-4 font-medium sm:pl-6">
                                            Started
                                        </th>
                                        <th className="py-3 pr-4 font-medium">
                                            Expires
                                        </th>
                                        <th className="py-3 pr-4 font-medium">
                                            Ended
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {query_request.sessions.map((session) => (
                                        <tr
                                            key={session.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-3.5 pr-4 pl-4 sm:pl-6">
                                                {formatDate(
                                                    session.started_at,
                                                    userTimezone,
                                                )}
                                            </td>
                                            <td className="py-3.5 pr-4">
                                                {formatDate(
                                                    session.expires_at,
                                                    userTimezone,
                                                )}
                                            </td>
                                            <td className="py-3.5 pr-4">
                                                {formatDate(
                                                    session.ended_at,
                                                    userTimezone,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {query_request.sessions.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={3}
                                                className="py-10 text-center text-muted-foreground"
                                            >
                                                No access sessions started.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                {can_review && (
                    <section
                        id="review-request"
                        aria-labelledby="review-title"
                        className="border-y bg-card sm:rounded-lg sm:border"
                    >
                        <div className="border-b px-4 py-3 sm:px-5">
                            <h2
                                id="review-title"
                                className="text-sm font-semibold"
                            >
                                Review request
                            </h2>
                        </div>
                        <div className="p-4 sm:p-5">
                            <Form
                                {...QueryReviewController.store.form(
                                    query_request.id,
                                )}
                                options={{ preserveScroll: true }}
                                className="grid gap-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-2 md:w-80">
                                            <Label htmlFor="decision">
                                                Decision
                                            </Label>
                                            <select
                                                id="decision"
                                                name="decision"
                                                className="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                                required
                                            >
                                                <option value="approved">
                                                    Approve
                                                </option>
                                                <option value="rejected">
                                                    Reject
                                                </option>
                                            </select>
                                            <InputError
                                                message={errors.decision}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="comment">
                                                Comment
                                            </Label>
                                            <textarea
                                                id="comment"
                                                name="comment"
                                                rows={3}
                                                className="min-h-24 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                            />
                                            <InputError
                                                message={errors.comment}
                                            />
                                        </div>
                                        <Button
                                            className="w-fit"
                                            disabled={processing}
                                        >
                                            <Check />
                                            Submit Review
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </div>
                    </section>
                )}

                <section
                    aria-labelledby="execution-history-title"
                    className="overflow-hidden border-y bg-card sm:rounded-lg sm:border"
                >
                    <div className="flex items-center justify-between border-b px-4 py-3 sm:px-5">
                        <h2
                            id="execution-history-title"
                            className="text-sm font-semibold"
                        >
                            Execution history
                        </h2>
                        <span className="text-xs text-muted-foreground">
                            {query_request.executions.total}{' '}
                            {query_request.executions.total === 1
                                ? 'execution'
                                : 'executions'}
                        </span>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1050px] text-sm">
                            <thead>
                                <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground uppercase">
                                    <th className="py-3 pr-4 pl-4 font-medium sm:pl-6">
                                        Status
                                    </th>
                                    <th className="py-3 pr-4 font-medium">
                                        Type
                                    </th>
                                    <th className="py-3 pr-4 font-medium">
                                        Statement / SQL
                                    </th>
                                    <th className="py-3 pr-4 font-medium">
                                        Connection
                                    </th>
                                    <th className="py-3 pr-4 font-medium">
                                        Started
                                    </th>
                                    <th className="py-3 pr-4 font-medium">
                                        Duration
                                    </th>
                                    <th className="py-3 pr-4 font-medium">
                                        Executor
                                    </th>
                                    <th className="py-3 pr-4 font-medium">
                                        Rows
                                    </th>
                                    <th className="py-3 pr-4 font-medium">
                                        Error
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {query_request.executions.data.map(
                                    (execution) => {
                                        const isExpanded =
                                            expandedExecutionIds.includes(
                                                execution.id,
                                            );

                                        return (
                                            <Fragment key={execution.id}>
                                                <tr className="border-b align-top transition-colors last:border-0 hover:bg-accent/40">
                                                    <td className="py-3.5 pr-4 pl-4 sm:pl-6">
                                                        <StatusBadge
                                                            value={
                                                                execution.status
                                                            }
                                                        />
                                                    </td>
                                                    <td className="py-3.5 pr-4">
                                                        <StatusBadge
                                                            value={
                                                                execution.query_type
                                                            }
                                                        />
                                                    </td>
                                                    <td className="max-w-[36rem] min-w-80 py-3.5 pr-4">
                                                        <button
                                                            type="button"
                                                            className="group flex w-full items-start gap-2 rounded-md text-left"
                                                            onClick={() =>
                                                                toggleExecutionSql(
                                                                    execution.id,
                                                                )
                                                            }
                                                        >
                                                            <ChevronDown
                                                                className={`mt-0.5 size-3.5 shrink-0 text-muted-foreground transition-transform ${
                                                                    isExpanded
                                                                        ? 'rotate-180'
                                                                        : ''
                                                                }`}
                                                            />
                                                            <code className="line-clamp-2 font-mono text-xs text-muted-foreground group-hover:text-foreground">
                                                                {execution.statement_position && (
                                                                    <span className="mr-2 font-sans font-medium text-foreground">
                                                                        #
                                                                        {
                                                                            execution.statement_position
                                                                        }
                                                                    </span>
                                                                )}
                                                                {execution.sql ??
                                                                    'SQL not recorded'}
                                                            </code>
                                                        </button>
                                                    </td>
                                                    <td className="max-w-48 py-3.5 pr-4">
                                                        <span className="block truncate text-xs font-medium">
                                                            {
                                                                execution
                                                                    .connection
                                                                    .name
                                                            }
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {
                                                                execution
                                                                    .connection
                                                                    .driver
                                                            }
                                                        </span>
                                                    </td>
                                                    <td className="py-3.5 pr-4 text-muted-foreground">
                                                        {formatDate(
                                                            execution.started_at,
                                                            userTimezone,
                                                        )}
                                                    </td>
                                                    <td className="py-3.5 pr-4 font-mono text-xs">
                                                        {execution.duration_ms ??
                                                            0}{' '}
                                                        ms
                                                    </td>
                                                    <td className="py-3.5 pr-4 font-medium">
                                                        {execution.executor ??
                                                            'Not recorded'}
                                                    </td>
                                                    <td className="py-3.5 pr-4 font-mono text-xs">
                                                        {execution.row_count ??
                                                            0}
                                                        {execution.result_truncated
                                                            ? '+'
                                                            : ''}
                                                    </td>
                                                    <td className="max-w-96 truncate py-3.5 pr-4 text-muted-foreground">
                                                        {execution.error_message ??
                                                            ''}
                                                    </td>
                                                </tr>
                                                {isExpanded && (
                                                    <tr className="border-b bg-muted/20">
                                                        <td
                                                            colSpan={8}
                                                            className="px-4 py-3 sm:px-6"
                                                        >
                                                            <div className="grid gap-3">
                                                                <div>
                                                                    <div className="mb-2 text-xs font-medium text-muted-foreground uppercase">
                                                                        SQL
                                                                    </div>
                                                                    <pre className="max-h-72 overflow-auto rounded-md border bg-background p-3 font-mono text-xs leading-5 whitespace-pre-wrap">
                                                                        {execution.sql ??
                                                                            'SQL not recorded'}
                                                                    </pre>
                                                                </div>
                                                                <div>
                                                                    <div className="mb-2 text-xs font-medium text-muted-foreground uppercase">
                                                                        Result
                                                                    </div>
                                                                    <ExecutionResult
                                                                        execution={
                                                                            execution
                                                                        }
                                                                    />
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                )}
                                            </Fragment>
                                        );
                                    },
                                )}
                                {query_request.executions.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No executions recorded.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    <Pagination pagination={query_request.executions} />
                </section>

                {query_request.reviews.length > 0 && (
                    <section
                        aria-labelledby="reviews-title"
                        className="overflow-hidden border-y bg-card sm:rounded-lg sm:border"
                    >
                        <div className="border-b px-4 py-3 sm:px-5">
                            <h2
                                id="reviews-title"
                                className="text-sm font-semibold"
                            >
                                Review history
                            </h2>
                        </div>
                        <div className="divide-y">
                            {query_request.reviews.map((review) => (
                                <div
                                    key={review.id}
                                    className="px-4 py-3 text-sm sm:px-5"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusBadge
                                            value={
                                                review.decision === 'approved'
                                                    ? 'approved'
                                                    : 'rejected'
                                            }
                                            label={statusLabel(review.decision)}
                                        />
                                        <span className="font-medium">
                                            {review.reviewer}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {formatDate(
                                                review.created_at,
                                                userTimezone,
                                            )}
                                        </span>
                                    </div>
                                    {review.comment && (
                                        <p className="mt-2 text-muted-foreground">
                                            {review.comment}
                                        </p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}

QueryRequestShow.layout = {
    breadcrumbs: [
        {
            title: 'Query Requests',
            href: index(),
        },
    ],
};
