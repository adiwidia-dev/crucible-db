import { Form, Head, Link, usePage, usePoll } from '@inertiajs/react';
import {
    Check,
    CircleCheck,
    CircleMinus,
    CircleX,
    ChevronDown,
    Download,
    FileCode2,
    KeyRound,
    Pencil,
    RotateCcw,
    Send,
    Trash2,
} from 'lucide-react';
import { Fragment, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { format } from 'sql-formatter';
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
    requires_approval: boolean;
    scheduled_at: string | null;
    access_duration_minutes: number | null;
    created_at: string | null;
    approved_at: string | null;
    dispatched_at: string | null;
    completed_at: string | null;
    last_error: string | null;
    result_summary: Record<string, unknown> | null;
    requester: string;
    approved_by: string | null;
    connection: {
        id: number;
        name: string;
        driver: string;
    };
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
    can_start_session: boolean;
    can_delete: boolean;
};

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
    can_start_session,
    can_delete,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const userTimezone = auth.user.timezone ?? 'UTC';
    const lastExecution = query_request.executions.data[0];
    const [expandedExecutionIds, setExpandedExecutionIds] = useState<number[]>(
        [],
    );
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
            only: ['query_request', 'can_dispatch'],
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
                    description={`Request #${query_request.id} · ${query_request.connection.name} · ${query_request.requester}`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge value={query_request.status} />
                            {can_update && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={QueryRequestController.edit(
                                            query_request.id,
                                        )}
                                    >
                                        <Pencil />
                                        Edit request
                                    </Link>
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
                    }
                />

                <section
                    aria-labelledby="request-details-title"
                    className="border-y bg-card sm:rounded-lg sm:border"
                >
                    <div className="flex flex-col gap-3 border-b px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <h2
                            id="request-details-title"
                            className="text-sm font-semibold"
                        >
                            Request details
                        </h2>
                        <div className="flex flex-wrap items-center gap-1.5">
                            <StatusBadge
                                value={query_request.request_kind}
                                label={queryRequestKindLabel(
                                    query_request.request_kind,
                                )}
                            />
                            <StatusBadge
                                value={query_request.query_type}
                                label={statusLabel(query_request.query_type)}
                            />
                            <StatusBadge
                                value={
                                    query_request.requires_approval
                                        ? 'pending_review'
                                        : 'completed'
                                }
                                label={
                                    query_request.requires_approval
                                        ? 'Approval required'
                                        : 'No approval required'
                                }
                            />
                        </div>
                    </div>
                    <div className="px-4 py-4">
                        <dl className="grid grid-cols-2 gap-x-6 gap-y-4 text-sm md:grid-cols-4 xl:grid-cols-7">
                            <div className="min-w-0">
                                <dt className="text-xs text-muted-foreground">
                                    Connection
                                </dt>
                                <dd className="mt-1 flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {query_request.connection.name}
                                    </span>
                                    <StatusBadge
                                        value={query_request.connection.driver}
                                        label={driverLabel(
                                            query_request.connection.driver,
                                        )}
                                    />
                                </dd>
                            </div>
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
                                    Approved By
                                </dt>
                                <dd className="mt-1 truncate font-medium">
                                    {query_request.approved_by ?? 'Not set'}
                                </dd>
                            </div>
                            <div className="min-w-0">
                                <dt className="text-xs text-muted-foreground">
                                    Window
                                </dt>
                                <dd className="mt-1 font-medium">
                                    {query_request.request_kind ===
                                    'query_access'
                                        ? `${query_request.access_duration_minutes ?? 60} minutes`
                                        : formatDate(
                                              query_request.scheduled_at,
                                              userTimezone,
                                          )}
                                </dd>
                            </div>
                            <div className="min-w-0">
                                <dt className="text-xs text-muted-foreground">
                                    Created
                                </dt>
                                <dd className="mt-1 font-medium">
                                    {formatDate(
                                        query_request.created_at,
                                        userTimezone,
                                    )}
                                </dd>
                            </div>
                            <div className="min-w-0">
                                <dt className="text-xs text-muted-foreground">
                                    Approved
                                </dt>
                                <dd className="mt-1 font-medium">
                                    {formatDate(
                                        query_request.approved_at,
                                        userTimezone,
                                    )}
                                </dd>
                            </div>
                            <div className="min-w-0">
                                <dt className="text-xs text-muted-foreground">
                                    Completed
                                </dt>
                                <dd className="mt-1 font-medium">
                                    {formatDate(
                                        query_request.completed_at,
                                        userTimezone,
                                    )}
                                </dd>
                            </div>
                        </dl>

                        {query_request.description && (
                            <div className="mt-4 border-t pt-4 text-sm leading-6 text-foreground/80">
                                {query_request.description}
                            </div>
                        )}

                        {query_request.last_error &&
                            !hasStatementLevelFailure && (
                                <div className="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900/70 dark:bg-red-950/40 dark:text-red-300">
                                    {query_request.last_error}
                                </div>
                            )}
                    </div>
                </section>

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
                        {can_dispatch && (
                            <div className="flex flex-col gap-3 border-t bg-muted/20 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                                <div className="text-sm text-muted-foreground">
                                    Approved and ready for ordered execution.
                                </div>
                                <Form
                                    {...QueryRequestController.dispatch.form(
                                        query_request.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing, recentlySuccessful }) => (
                                        <Button
                                            className="w-full sm:w-fit"
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
                                                  : 'Execute batch'}
                                        </Button>
                                    )}
                                </Form>
                            </div>
                        )}
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
                            <table className="w-full text-sm">
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
                        <table className="w-full text-sm">
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
