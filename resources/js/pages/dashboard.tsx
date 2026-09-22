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
import { SemanticIcon } from '@/components/crucible/semantic-icon';
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

type OperationalQueueType =
    | 'active_session'
    | 'failed_execution'
    | 'pending_review'
    | 'policy_review'
    | 'scheduled_execution';

type QueueFilter = 'all' | 'attention' | 'live' | 'scheduled';

type OperationalQueueItem = {
    id: number;
    title: string;
    type: OperationalQueueType;
    connection: string | null;
    actor: string | null;
    timestamp: string | null;
    detail: string | null;
    related_title: string | null;
    count: number | null;
    driver: 'pgsql' | 'mysql' | null;
    request_kind: QueryRequestKind | null;
    query_type: QueryType | null;
    requested_access_mode: 'none' | 'read' | 'write' | null;
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
    operational_queue: OperationalQueueItem[];
};

type QueuePresentation = {
    label: string;
    icon: LucideIcon;
    tone: SemanticTone;
    status: QueryRequestStatus | 'active' | 'pending';
    filter: Exclude<QueueFilter, 'all'>;
};

const queuePresentation: Record<OperationalQueueType, QueuePresentation> = {
    failed_execution: {
        label: 'Failed',
        icon: AlertTriangle,
        tone: 'danger',
        status: 'failed',
        filter: 'attention',
    },
    pending_review: {
        label: 'Review',
        icon: FileCheck2,
        tone: 'pending',
        status: 'pending_review',
        filter: 'attention',
    },
    policy_review: {
        label: 'Policy',
        icon: FileSearch,
        tone: 'pending',
        status: 'pending',
        filter: 'attention',
    },
    active_session: {
        label: 'Session',
        icon: KeyRound,
        tone: 'success',
        status: 'active',
        filter: 'live',
    },
    scheduled_execution: {
        label: 'Scheduled',
        icon: CalendarClock,
        tone: 'info',
        status: 'scheduled',
        filter: 'scheduled',
    },
};

function SummaryGroup({
    label,
    value,
    valueLabel,
    icon,
    tone,
    children,
}: {
    label: string;
    value: number;
    valueLabel: string;
    icon: LucideIcon;
    tone: SemanticTone;
    children: ReactNode;
}) {
    return (
        <div className="flex min-h-28 gap-3 rounded-lg border bg-card px-4 py-4 sm:px-5">
            <SemanticIcon
                icon={icon}
                tone={tone}
                size="sm"
                className="mt-0.5"
            />
            <div className="min-w-0 flex-1">
                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {label}
                </p>
                <p className="mt-1 flex items-baseline gap-2">
                    <span className="text-2xl leading-none font-semibold tracking-[-0.025em]">
                        {value}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {valueLabel}
                    </span>
                </p>
                <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs">
                    {children}
                </div>
            </div>
        </div>
    );
}

function queueHref(item: OperationalQueueItem) {
    if (item.type === 'active_session') {
        return showQuerySession(item.id);
    }

    if (item.type === 'policy_review') {
        return editSqlPolicy({ query: { candidate: item.id } });
    }

    return showQueryRequest(item.id);
}

function queueMetadata(item: OperationalQueueItem): string {
    if (item.type === 'policy_review') {
        return [
            item.driver ? driverLabel(item.driver) : null,
            item.related_title ?? 'Deployment draft',
            item.actor,
        ]
            .filter((value): value is string => Boolean(value))
            .join(' · ');
    }

    return [item.connection, item.actor]
        .filter((value): value is string => Boolean(value))
        .join(' · ');
}

function queueTimestamp(item: OperationalQueueItem, timezone: string): string {
    if (!item.timestamp) {
        return 'Time unavailable';
    }

    if (item.type === 'active_session') {
        return formatRemaining(item.timestamp);
    }

    return formatDate(item.timestamp, timezone);
}

function QueueEmptyState({ filter }: { filter: QueueFilter }) {
    const copy = {
        all: {
            title: 'No operational work is waiting.',
            detail: 'Reviews, failures, scheduled work, and live access will appear here.',
        },
        attention: {
            title: 'Nothing needs attention.',
            detail: 'Reviews and failed executions will appear here when action is required.',
        },
        scheduled: {
            title: 'No executions are scheduled.',
            detail: 'Approved work with a future execution time will appear here.',
        },
        live: {
            title: 'No query-access sessions are active.',
            detail: 'Approved query-access requests appear here while their session is open.',
        },
    }[filter];

    return (
        <div className="flex items-start gap-3 px-4 py-8 sm:px-5">
            <SemanticIcon
                icon={CheckCircle2}
                tone="success"
                size="sm"
                className="mt-0.5"
            />
            <div className="min-w-0">
                <p className="text-sm font-medium">{copy.title}</p>
                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                    {copy.detail}
                </p>
            </div>
        </div>
    );
}

function OperationalQueue({
    items,
    filter,
    timezone,
}: {
    items: OperationalQueueItem[];
    filter: QueueFilter;
    timezone: string;
}) {
    if (items.length === 0) {
        return <QueueEmptyState filter={filter} />;
    }

    return (
        <div className="divide-y">
            {items.map((item) => {
                const presentation = queuePresentation[item.type];

                return (
                    <Link
                        key={`${item.type}:${item.id}`}
                        href={queueHref(item)}
                        prefetch
                        className="group flex flex-col gap-3 px-4 py-3.5 transition-colors duration-150 ease-out hover:bg-accent/60 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-hidden focus-visible:ring-inset motion-reduce:transition-none sm:flex-row sm:items-start sm:px-5"
                    >
                        <div className="flex min-w-0 flex-1 gap-3">
                            <SemanticIcon
                                icon={presentation.icon}
                                tone={presentation.tone}
                                size="sm"
                                className="mt-0.5"
                            />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <StatusBadge
                                        value={presentation.status}
                                        label={presentation.label}
                                    />
                                    <span className="min-w-0 truncate text-sm font-medium group-hover:text-primary">
                                        {item.title}
                                    </span>
                                    {item.request_kind === 'query_access' ? (
                                        <SessionAccessBadge
                                            mode={
                                                item.requested_access_mode ===
                                                'write'
                                                    ? 'write'
                                                    : 'read'
                                            }
                                        />
                                    ) : (
                                        item.query_type && (
                                            <StatusBadge
                                                value={item.query_type}
                                            />
                                        )
                                    )}
                                </div>
                                <p className="mt-1 truncate text-xs text-muted-foreground">
                                    {queueMetadata(item)}
                                </p>
                                {item.detail && (
                                    <p className="mt-1.5 line-clamp-1 text-xs text-destructive">
                                        {item.detail}
                                    </p>
                                )}
                            </div>
                        </div>
                        <div className="flex shrink-0 items-center justify-between gap-3 pl-10 text-xs text-muted-foreground sm:justify-end sm:pl-0 sm:text-right">
                            <div>
                                {item.count !== null && (
                                    <p>
                                        {item.count}{' '}
                                        {item.count === 1
                                            ? 'request'
                                            : 'requests'}
                                    </p>
                                )}
                                <p
                                    className={cn(
                                        item.type === 'active_session' &&
                                            'font-medium text-foreground',
                                        item.count !== null && 'mt-1',
                                    )}
                                >
                                    {queueTimestamp(item, timezone)}
                                </p>
                            </div>
                            <ArrowRight className="size-3.5 shrink-0 opacity-50 transition-opacity group-hover:opacity-100 motion-reduce:transition-none" />
                        </div>
                    </Link>
                );
            })}
        </div>
    );
}

export default function Dashboard({
    summary,
    operational_queue = [],
    native_proxy_health,
}: DashboardProps) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const userTimezone = auth.user.timezone ?? 'UTC';
    const isAdmin = Boolean(auth.user.roles?.some((role) => role.is_admin));
    const [queueFilter, setQueueFilter] = useState<QueueFilter>('all');
    const [, setTimerTick] = useState(0);

    usePoll(30000, {
        only: [
            'summary',
            'operational_queue',
            'native_proxy_health',
            'native_proxy_status',
        ],
    });

    useEffect(() => {
        const interval = window.setInterval(() => {
            setTimerTick((value) => value + 1);
        }, 30000);

        return () => window.clearInterval(interval);
    }, []);

    const attentionCount =
        summary.pending_reviews + summary.policy_reviews + summary.failed;
    const queueFilterOptions: Array<{
        value: QueueFilter;
        label: string;
        count: number;
    }> = [
        {
            value: 'all',
            label: 'All',
            count: attentionCount + summary.scheduled + summary.active_sessions,
        },
        {
            value: 'attention',
            label: 'Attention',
            count: attentionCount,
        },
        {
            value: 'scheduled',
            label: 'Scheduled',
            count: summary.scheduled,
        },
        {
            value: 'live',
            label: 'Live',
            count: summary.active_sessions,
        },
    ];
    const filteredQueueItems = operational_queue.filter(
        (item) =>
            queueFilter === 'all' ||
            queuePresentation[item.type].filter === queueFilter,
    );
    const visibleQueueItems = filteredQueueItems.slice(0, 10);

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
                    className="grid gap-3 lg:grid-cols-3"
                >
                    <SummaryGroup
                        label="Attention"
                        value={attentionCount}
                        valueLabel={
                            attentionCount === 1
                                ? 'item needs action'
                                : 'items need action'
                        }
                        icon={AlertTriangle}
                        tone={attentionCount > 0 ? 'pending' : 'neutral'}
                    >
                        <Link
                            href={queryRequestsIndex({
                                query: { status: 'pending_review' },
                            })}
                            prefetch
                            className="font-medium text-primary hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-hidden"
                        >
                            Reviews {summary.pending_reviews}
                        </Link>
                        {isAdmin && (
                            <Link
                                href={editSqlPolicy()}
                                prefetch
                                className="font-medium text-primary hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-hidden"
                            >
                                Policy {summary.policy_reviews}
                            </Link>
                        )}
                        <Link
                            href={queryRequestsIndex({
                                query: { status: 'failed' },
                            })}
                            prefetch
                            className="font-medium text-primary hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-hidden"
                        >
                            Failed {summary.failed}
                        </Link>
                    </SummaryGroup>

                    <SummaryGroup
                        label="Upcoming"
                        value={summary.scheduled}
                        valueLabel={
                            summary.scheduled === 1
                                ? 'scheduled execution'
                                : 'scheduled executions'
                        }
                        icon={CalendarClock}
                        tone="info"
                    >
                        <Link
                            href={queryRequestsIndex({
                                query: { status: 'scheduled' },
                            })}
                            prefetch
                            className="font-medium text-primary hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-hidden"
                        >
                            View scheduled work
                        </Link>
                    </SummaryGroup>

                    <SummaryGroup
                        label="Live access"
                        value={summary.active_sessions}
                        valueLabel={
                            summary.active_sessions === 1
                                ? 'active session'
                                : 'active sessions'
                        }
                        icon={KeyRound}
                        tone={
                            summary.active_sessions > 0 ? 'success' : 'neutral'
                        }
                    >
                        <button
                            type="button"
                            onClick={() => setQueueFilter('live')}
                            className="font-medium text-primary hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-hidden"
                        >
                            Show sessions
                        </button>
                        {native_proxy_health.status !== 'disabled' && (
                            <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                <Wifi className="size-3.5" />
                                {summary.native_proxy_connections}{' '}
                                {summary.native_proxy_connections === 1
                                    ? 'proxy connection'
                                    : 'proxy connections'}
                            </span>
                        )}
                    </SummaryGroup>
                </section>

                <section
                    aria-labelledby="operational-queue-title"
                    className="overflow-hidden rounded-lg border bg-card"
                >
                    <div className="flex flex-col gap-4 border-b px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <div>
                            <h2
                                id="operational-queue-title"
                                className="text-base font-semibold"
                            >
                                Operational queue
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Current work ordered by priority. Select an item
                                to open its workflow.
                            </p>
                        </div>
                        <div
                            role="group"
                            aria-label="Filter operational queue"
                            className="flex flex-wrap items-center gap-1"
                        >
                            {queueFilterOptions.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    aria-pressed={queueFilter === option.value}
                                    onClick={() => setQueueFilter(option.value)}
                                    className={cn(
                                        'inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium transition-colors duration-150 ease-out focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-hidden motion-reduce:transition-none',
                                        queueFilter === option.value
                                            ? 'border-border bg-muted text-foreground'
                                            : 'border-transparent text-muted-foreground hover:border-border hover:bg-accent hover:text-foreground',
                                    )}
                                >
                                    {option.label}
                                    <span className="text-[11px] tabular-nums opacity-70">
                                        {option.count}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>
                    <OperationalQueue
                        items={visibleQueueItems}
                        filter={queueFilter}
                        timezone={userTimezone}
                    />
                    {filteredQueueItems.length > 10 && (
                        <p className="border-t px-4 py-2.5 text-xs text-muted-foreground sm:px-5">
                            Showing the 10 highest-priority items in this view.
                        </p>
                    )}
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
