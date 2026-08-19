import { Head, Link, usePage, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CalendarClock,
    CheckCircle2,
    FileCheck2,
    KeyRound,
    Plus,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/crucible/page-header';
import { StatusBadge } from '@/components/crucible/status-badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatRemaining } from '@/lib/crucible';
import type {
    QueryRequestKind,
    QueryRequestStatus,
    QueryType,
} from '@/lib/crucible';
import { dashboard } from '@/routes';
import {
    create as createQueryRequest,
    index as queryRequestsIndex,
    show as showQueryRequest,
} from '@/routes/query-requests';
import { show as showQuerySession } from '@/routes/query-sessions';
import type { Auth } from '@/types';

type DashboardRequest = {
    id: number;
    title: string;
    status: QueryRequestStatus;
    request_kind: QueryRequestKind;
    query_type: QueryType;
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

type DashboardProps = {
    summary: {
        pending_reviews: number;
        scheduled: number;
        failed: number;
        active_sessions: number;
    };
    pending_reviews: DashboardRequest[];
    scheduled_requests: DashboardRequest[];
    failed_requests: DashboardRequest[];
    expiring_sessions: ExpiringSession[];
};

type QueueSectionProps = {
    id: string;
    title: string;
    detail: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
};

function QueueSection({
    id,
    title,
    detail,
    action,
    children,
    className,
}: QueueSectionProps) {
    return (
        <section
            aria-labelledby={id}
            className={`overflow-hidden border-y bg-card sm:rounded-lg sm:border ${className ?? ''}`}
        >
            <div className="flex items-start justify-between gap-4 border-b px-4 py-3 sm:px-5">
                <div>
                    <h2 id={id} className="text-sm font-semibold">
                        {title}
                    </h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {detail}
                    </p>
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

function EmptyQueue({ children }: { children: ReactNode }) {
    return (
        <div className="px-4 py-8 text-sm text-muted-foreground sm:px-5">
            <CheckCircle2 className="mb-2 size-4 text-emerald-600 dark:text-emerald-400" />
            {children}
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
                {queue === 'review'
                    ? 'No requests are waiting for your review.'
                    : queue === 'scheduled'
                      ? 'No executions are scheduled.'
                      : 'No failed executions are visible to you.'}
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
                            <StatusBadge value={request.query_type} />
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
            <EmptyQueue>No active database sessions need attention.</EmptyQueue>
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

export default function Dashboard({
    summary,
    pending_reviews,
    scheduled_requests,
    failed_requests,
    expiring_sessions,
}: DashboardProps) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const userTimezone = auth.user.timezone ?? 'UTC';
    const [, setTimerTick] = useState(0);

    usePoll(30000, {
        only: [
            'summary',
            'pending_reviews',
            'scheduled_requests',
            'failed_requests',
            'expiring_sessions',
        ],
    });

    useEffect(() => {
        const interval = window.setInterval(() => {
            setTimerTick((value) => value + 1);
        }, 30000);

        return () => window.clearInterval(interval);
    }, []);

    const summaryItems = [
        {
            label: 'Needs review',
            value: summary.pending_reviews,
            icon: FileCheck2,
            href: queryRequestsIndex.url({
                query: { status: 'pending_review' },
            }),
        },
        {
            label: 'Scheduled',
            value: summary.scheduled,
            icon: CalendarClock,
            href: queryRequestsIndex.url({ query: { status: 'scheduled' } }),
        },
        {
            label: 'Failed',
            value: summary.failed,
            icon: AlertTriangle,
            href: queryRequestsIndex.url({ query: { status: 'failed' } }),
        },
        {
            label: 'Active sessions',
            value: summary.active_sessions,
            icon: KeyRound,
            href: '#active-sessions',
        },
    ];

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
                    className="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4"
                >
                    {summaryItems.map((item) => {
                        const content = (
                            <>
                                <span className="flex size-9 items-center justify-center rounded-md bg-muted text-muted-foreground transition-colors group-hover:bg-accent group-hover:text-primary">
                                    <item.icon className="size-4" />
                                </span>
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
                                className="group flex min-h-20 items-center gap-3 border bg-card px-3 py-3 transition-colors duration-150 ease-out hover:border-primary/30 hover:bg-accent/35 focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-hidden motion-reduce:transition-none sm:min-h-22 sm:rounded-lg sm:px-4 sm:py-4"
                            >
                                {content}
                            </a>
                        ) : (
                            <Link
                                key={item.label}
                                href={item.href}
                                prefetch
                                className="group flex min-h-20 items-center gap-3 border bg-card px-3 py-3 transition-colors duration-150 ease-out hover:border-primary/30 hover:bg-accent/35 focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-hidden motion-reduce:transition-none sm:min-h-22 sm:rounded-lg sm:px-4 sm:py-4"
                            >
                                {content}
                            </Link>
                        );
                    })}
                </section>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(20rem,0.8fr)]">
                    <QueueSection
                        id="attention-title"
                        title="Needs attention"
                        detail="Review work and failures that need a decision or follow-up."
                    >
                        <div className="border-b">
                            <div className="flex items-center justify-between gap-3 px-4 py-2.5 sm:px-5">
                                <div className="flex items-center gap-2 text-sm font-medium">
                                    <FileCheck2 className="size-4 text-amber-600 dark:text-amber-400" />
                                    Pending review
                                </div>
                                <Link
                                    href={queryRequestsIndex({
                                        query: { status: 'pending_review' },
                                    })}
                                    className="text-xs font-medium text-primary hover:underline"
                                >
                                    View all
                                </Link>
                            </div>
                            <RequestQueue
                                requests={pending_reviews}
                                queue="review"
                                timezone={userTimezone}
                            />
                        </div>
                        <div>
                            <div className="flex items-center justify-between gap-3 px-4 py-2.5 sm:px-5">
                                <div className="flex items-center gap-2 text-sm font-medium">
                                    <AlertTriangle className="size-4 text-destructive" />
                                    Failed execution
                                </div>
                                <Link
                                    href={queryRequestsIndex({
                                        query: { status: 'failed' },
                                    })}
                                    className="text-xs font-medium text-primary hover:underline"
                                >
                                    View all
                                </Link>
                            </div>
                            <RequestQueue
                                requests={failed_requests}
                                queue="failed"
                                timezone={userTimezone}
                            />
                        </div>
                    </QueueSection>

                    <div className="grid content-start gap-6">
                        <QueueSection
                            id="scheduled-title"
                            title="Scheduled work"
                            detail="Approved executions waiting for their scheduled time."
                            action={
                                <Link
                                    href={queryRequestsIndex({
                                        query: { status: 'scheduled' },
                                    })}
                                    className="text-xs font-medium text-primary hover:underline"
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
                        >
                            <SessionQueue sessions={expiring_sessions} />
                        </QueueSection>
                    </div>
                </div>
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
