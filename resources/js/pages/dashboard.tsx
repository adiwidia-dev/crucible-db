import { Head, Link, usePage, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CalendarClock,
    CheckCircle2,
    FileCheck2,
    FileSearch,
    KeyRound,
    Plus,
    Wifi,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/crucible/page-header';
import {
    SemanticIcon,
    semanticToneForStatus,
} from '@/components/crucible/semantic-icon';
import type { SemanticTone } from '@/components/crucible/semantic-icon';
import {
    SessionAccessBadge,
    StatusBadge,
} from '@/components/crucible/status-badge';
import type { NativeProxyHealthSnapshot } from '@/components/native-proxy/health-status';
import { Button } from '@/components/ui/button';
import { driverLabel, formatDate, formatRemaining } from '@/lib/crucible';
import type {
    QueryRequestKind,
    QueryRequestStatus,
    QueryType,
} from '@/lib/crucible';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    create as createQueryRequest,
    index as queryRequestsIndex,
    show as showQueryRequest,
} from '@/routes/query-requests';
import { show as showQuerySession } from '@/routes/query-sessions';
import { edit as editSqlPolicy } from '@/routes/sql-statement-policy';
import type { Auth } from '@/types';

type DashboardRequest = {
    id: number;
    title: string;
    status: QueryRequestStatus;
    request_kind: QueryRequestKind;
    query_type: QueryType;
    requested_access_mode: 'read' | 'write' | null;
    connection: string;
    requester: string;
    scheduled_at: string | null;
    created_at: string | null;
    completed_at: string | null;
    last_error: string | null;
};

type ExpiringSession = {
    id: number;
    request_id: number;
    title: string;
    connection: string;
    user: string;
    expires_at: string;
};

type PolicyReviewCandidate = {
    id: number;
    statement: string;
    driver: 'pgsql' | 'mysql';
    request_count: number;
    request_title: string | null;
    requester: string | null;
    requested_at: string | null;
};

type DashboardProps = {
    summary: {
        pending_reviews: number;
        policy_reviews: number;
        scheduled: number;
        failed: number;
        active_sessions: number;
        native_proxy_connections: number;
        native_proxy_instances: number;
    };
    native_proxy_health: NativeProxyHealthSnapshot;
    pending_reviews: DashboardRequest[];
    scheduled_requests: DashboardRequest[];
    failed_requests: DashboardRequest[];
    expiring_sessions: ExpiringSession[];
    policy_review_candidates: PolicyReviewCandidate[];
};

type QueueSectionProps = {
    id: string;
    title: string;
    detail: string;
    icon: LucideIcon;
    tone: SemanticTone;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
};

function QueueSection({
    id,
    title,
    detail,
    icon,
    tone,
    action,
    children,
    className,
}: QueueSectionProps) {
    return (
        <section
            aria-labelledby={id}
            className={cn(
                'h-full overflow-hidden border-y bg-card sm:rounded-lg sm:border',
                className,
            )}
        >
            <div className="flex min-h-20 items-start justify-between gap-4 border-b px-4 py-3 sm:px-5">
                <div className="flex min-w-0 items-start gap-3">
                    <SemanticIcon
                        icon={icon}
                        tone={tone}
                        size="sm"
                        className="mt-0.5"
                    />
                    <div className="min-w-0">
                        <h3 id={id} className="text-sm font-semibold">
                            {title}
                        </h3>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            {detail}
                        </p>
                    </div>
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

function EmptyQueue({ children }: { children: ReactNode }) {
    return (
        <div className="flex items-start gap-3 px-4 py-7 sm:px-5">
            <SemanticIcon
                icon={CheckCircle2}
                tone="success"
                size="sm"
                className="mt-0.5"
            />
            <div className="min-w-0 text-sm text-muted-foreground">
                {children}
            </div>
        </div>
    );
}

function RequestQueue({
    requests,
    queue,
    timezone,
}: {
    requests: DashboardRequest[];
    queue: 'review' | 'scheduled' | 'failed';
    timezone: string;
}) {
    if (requests.length === 0) {
        return (
            <EmptyQueue>
                <p className="font-medium text-foreground">
                    {queue === 'review'
                        ? 'No requests need your review.'
                        : queue === 'scheduled'
                          ? 'No executions are scheduled.'
                          : 'No failed executions are visible to you.'}
                </p>
                <p className="mt-1 text-xs leading-5">
                    {queue === 'review'
                        ? 'Approved work will leave this queue automatically.'
                        : queue === 'scheduled'
                          ? 'Choose a future execution time when creating a deployment batch.'
                          : 'Failures needing follow-up will appear here.'}
                </p>
            </EmptyQueue>
        );
    }

    return (
        <div className="divide-y">
            {requests.map((request) => (
                <Link
                    key={request.id}
                    href={showQueryRequest(request.id)}
                    prefetch
                    className="group flex gap-3 px-4 py-3 transition-colors hover:bg-accent/60 focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-hidden sm:px-5"
                >
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1.5">
                            <span className="truncate text-sm font-medium group-hover:text-primary">
                                {request.title}
                            </span>
                            {request.request_kind === 'query_access' ? (
                                <SessionAccessBadge
                                    mode={request.requested_access_mode}
                                />
                            ) : (
                                <StatusBadge value={request.query_type} />
                            )}
                        </div>
                        <p className="mt-1 truncate text-xs text-muted-foreground">
                            {request.connection} · {request.requester}
                        </p>
                        {queue === 'failed' && request.last_error && (
                            <p className="mt-2 line-clamp-1 text-xs text-destructive">
                                {request.last_error}
                            </p>
                        )}
                    </div>
                    <div className="flex shrink-0 items-start gap-2 text-right text-xs text-muted-foreground">
                        <span>
                            {queue === 'scheduled'
                                ? formatDate(request.scheduled_at, timezone)
                                : queue === 'failed'
                                  ? formatDate(request.completed_at, timezone)
                                  : formatDate(request.created_at, timezone)}
                        </span>
                        <ArrowRight className="mt-0.5 size-3.5 opacity-0 transition-opacity group-hover:opacity-100 motion-reduce:transition-none" />
                    </div>
                </Link>
            ))}
        </div>
    );
}

function SessionQueue({ sessions }: { sessions: ExpiringSession[] }) {
    if (sessions.length === 0) {
        return (
            <EmptyQueue>
                <p className="font-medium text-foreground">
                    No active sessions need attention.
                </p>
                <p className="mt-1 text-xs leading-5">
                    Approved query-access requests appear here while their
                    session is open.
                </p>
            </EmptyQueue>
        );
    }

    return (
        <div className="divide-y" id="active-sessions">
            {sessions.map((session) => (
                <Link
                    key={session.id}
                    href={showQuerySession(session.id)}
                    prefetch
                    className="group flex gap-3 px-4 py-3 transition-colors hover:bg-accent/60 focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-hidden sm:px-5"
                >
                    <div className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium group-hover:text-primary">
                            {session.title}
                        </span>
                        <p className="mt-1 truncate text-xs text-muted-foreground">
                            {session.connection} · {session.user}
                        </p>
                    </div>
                    <div className="flex shrink-0 items-start gap-2 text-right text-xs text-muted-foreground">
                        <span className="font-medium text-foreground">
                            {formatRemaining(session.expires_at)}
                        </span>
                        <ArrowRight className="mt-0.5 size-3.5 opacity-0 transition-opacity group-hover:opacity-100 motion-reduce:transition-none" />
                    </div>
                </Link>
            ))}
        </div>
    );
}

function PolicyReviewQueue({
    candidates,
    timezone,
}: {
    candidates: PolicyReviewCandidate[];
    timezone: string;
}) {
    if (candidates.length === 0) {
        return (
            <EmptyQueue>
                <p className="font-medium text-foreground">
                    No SQL policy reviews are waiting.
                </p>
                <p className="mt-1 text-xs leading-5">
                    Explicit developer requests for unsupported SQL will appear
                    here.
                </p>
            </EmptyQueue>
        );
    }

    return (
        <div className="divide-y">
            {candidates.map((candidate) => (
                <Link
                    key={candidate.id}
                    href={editSqlPolicy({ query: { candidate: candidate.id } })}
                    className="group flex gap-3 px-4 py-3 transition-colors hover:bg-accent/60 sm:px-5"
                >
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium group-hover:text-primary">
                            {candidate.statement}
                        </p>
                        <p className="mt-1 truncate text-xs text-muted-foreground">
                            {driverLabel(candidate.driver)} ·{' '}
                            {candidate.request_title ?? 'Deployment draft'}
                            {candidate.requester
                                ? ` · ${candidate.requester}`
                                : ''}
                        </p>
                    </div>
                    <div className="shrink-0 text-right text-xs text-muted-foreground">
                        <p>
                            {candidate.request_count}{' '}
                            {candidate.request_count === 1
                                ? 'request'
                                : 'requests'}
                        </p>
                        <p className="mt-1">
                            {formatDate(candidate.requested_at, timezone)}
                        </p>
                    </div>
                    <ArrowRight className="mt-0.5 size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-100" />
                </Link>
            ))}
        </div>
    );
}

export default function Dashboard({
    summary,
    pending_reviews,
    scheduled_requests,
    failed_requests,
    expiring_sessions,
    policy_review_candidates,
    native_proxy_health,
}: DashboardProps) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const userTimezone = auth.user.timezone ?? 'UTC';
    const isAdmin = Boolean(auth.user.roles?.some((role) => role.is_admin));
    const [, setTimerTick] = useState(0);

    usePoll(30000, {
        only: [
            'summary',
            'pending_reviews',
            'policy_review_candidates',
            'scheduled_requests',
            'failed_requests',
            'expiring_sessions',
            'native_proxy_health',
        ],
    });

    useEffect(() => {
        const interval = window.setInterval(() => {
            setTimerTick((value) => value + 1);
        }, 30000);

        return () => window.clearInterval(interval);
    }, []);

    const proxyTone = semanticToneForStatus(native_proxy_health.status);

    const summaryItems = [
        {
            label: 'Needs review',
            value: summary.pending_reviews,
            icon: FileCheck2,
            tone: 'pending' as const,
            href: queryRequestsIndex.url({
                query: { status: 'pending_review' },
            }),
        },
        ...(isAdmin
            ? [
                  {
                      label: 'Policy reviews',
                      value: summary.policy_reviews,
                      icon: FileSearch,
                      tone: 'pending' as const,
                      href: editSqlPolicy.url(),
                  },
              ]
            : []),
        {
            label: 'Scheduled',
            value: summary.scheduled,
            icon: CalendarClock,
            tone: 'info' as const,
            href: queryRequestsIndex.url({ query: { status: 'scheduled' } }),
        },
        {
            label: 'Failed',
            value: summary.failed,
            icon: AlertTriangle,
            tone: 'danger' as const,
            href: queryRequestsIndex.url({ query: { status: 'failed' } }),
        },
        {
            label: 'Active sessions',
            value: summary.active_sessions,
            icon: KeyRound,
            tone: 'success' as const,
            href: '#sessions-title',
        },
        ...(native_proxy_health.status === 'disabled'
            ? []
            : [
                  {
                      label: 'Proxy connections',
                      value: summary.native_proxy_connections,
                      icon: Wifi,
                      tone: proxyTone,
                      href: '#native-proxy-status',
                  },
              ]),
    ];
    const summaryColumnClassName =
        summaryItems.length === 6
            ? 'lg:grid-cols-3 xl:grid-cols-6'
            : summaryItems.length === 5
              ? 'lg:grid-cols-5'
              : 'lg:grid-cols-4';

    return (
        <>
            <Head title="Overview" />

            <div className="crucible-page">
                <PageHeader
                    icon={FileCheck2}
                    title="Overview"
                    description="Operational work across the database targets you can access."
                    actions={
                        <Button asChild>
                            <Link href={createQueryRequest()}>
                                <Plus />
                                New request
                            </Link>
                        </Button>
                    }
                />

                <section
                    aria-label="Operational summary"
                    className={cn(
                        'grid grid-cols-2 gap-2 sm:gap-3',
                        summaryColumnClassName,
                    )}
                >
                    {summaryItems.map((item, index) => {
                        const itemClassName = cn(
                            'group flex min-h-20 items-center gap-3 border bg-card px-3 py-3 transition-colors duration-150 ease-out hover:border-primary/30 hover:bg-accent/35 focus-visible:z-10 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-hidden focus-visible:ring-inset motion-reduce:transition-none sm:min-h-22 sm:rounded-lg sm:px-4 sm:py-4',
                            summaryItems.length % 2 === 1 &&
                                index === summaryItems.length - 1 &&
                                'col-span-2 lg:col-span-1',
                        );
                        const content = (
                            <>
                                <SemanticIcon
                                    icon={item.icon}
                                    tone={item.tone}
                                />
                                <div className="min-w-0">
                                    <div className="text-2xl leading-none font-semibold tracking-[-0.025em]">
                                        {item.value}
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {item.label}
                                    </div>
                                </div>
                            </>
                        );

                        return item.href.startsWith('#') ? (
                            <a
                                key={item.label}
                                href={item.href}
                                className={itemClassName}
                            >
                                {content}
                            </a>
                        ) : (
                            <Link
                                key={item.label}
                                href={item.href}
                                prefetch
                                className={itemClassName}
                            >
                                {content}
                            </Link>
                        );
                    })}
                </section>

                <section
                    aria-labelledby="operational-queues-title"
                    className="grid gap-4 sm:gap-5"
                >
                    <div className="px-1">
                        <h2
                            id="operational-queues-title"
                            className="text-base font-semibold"
                        >
                            Operational queues
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Review requests, upcoming executions, failures, and
                            live access.
                        </p>
                    </div>

                    <div className="grid gap-4 sm:gap-5 xl:grid-cols-2">
                        {isAdmin && (
                            <QueueSection
                                id="policy-review-title"
                                title="SQL policy review"
                                detail="Unsupported statements explicitly submitted for an administrator decision."
                                icon={FileSearch}
                                tone="pending"
                                className="xl:col-span-2"
                                action={
                                    <Link
                                        href={editSqlPolicy()}
                                        className="shrink-0 text-xs font-medium text-primary hover:underline"
                                    >
                                        View all
                                    </Link>
                                }
                            >
                                <PolicyReviewQueue
                                    candidates={policy_review_candidates}
                                    timezone={userTimezone}
                                />
                            </QueueSection>
                        )}
                        <QueueSection
                            id="pending-review-title"
                            title="Pending review"
                            detail="Requests waiting for an authorized decision."
                            icon={FileCheck2}
                            tone="pending"
                            className="xl:col-start-1 xl:row-start-1"
                            action={
                                <Link
                                    href={queryRequestsIndex({
                                        query: { status: 'pending_review' },
                                    })}
                                    className="shrink-0 text-xs font-medium text-primary hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-hidden"
                                >
                                    View all
                                </Link>
                            }
                        >
                            <RequestQueue
                                requests={pending_reviews}
                                queue="review"
                                timezone={userTimezone}
                            />
                        </QueueSection>

                        <QueueSection
                            id="failed-execution-title"
                            title="Failed execution"
                            detail="Deployments that stopped and need investigation."
                            icon={AlertTriangle}
                            tone="danger"
                            className="xl:col-start-1 xl:row-start-2"
                            action={
                                <Link
                                    href={queryRequestsIndex({
                                        query: { status: 'failed' },
                                    })}
                                    className="shrink-0 text-xs font-medium text-primary hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-hidden"
                                >
                                    View all
                                </Link>
                            }
                        >
                            <RequestQueue
                                requests={failed_requests}
                                queue="failed"
                                timezone={userTimezone}
                            />
                        </QueueSection>

                        <QueueSection
                            id="scheduled-title"
                            title="Scheduled work"
                            detail="Approved executions waiting for their scheduled time."
                            icon={CalendarClock}
                            tone="info"
                            className="xl:col-start-2 xl:row-start-1"
                            action={
                                <Link
                                    href={queryRequestsIndex({
                                        query: { status: 'scheduled' },
                                    })}
                                    className="shrink-0 text-xs font-medium text-primary hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-hidden"
                                >
                                    View all
                                </Link>
                            }
                        >
                            <RequestQueue
                                requests={scheduled_requests}
                                queue="scheduled"
                                timezone={userTimezone}
                            />
                        </QueueSection>

                        <QueueSection
                            id="sessions-title"
                            title="Active sessions"
                            detail="Open query-access sessions ordered by expiry."
                            icon={KeyRound}
                            tone="success"
                            className="xl:col-start-2 xl:row-start-2"
                        >
                            <SessionQueue sessions={expiring_sessions} />
                        </QueueSection>
                    </div>
                </section>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Overview',
            href: dashboard(),
        },
    ],
};
