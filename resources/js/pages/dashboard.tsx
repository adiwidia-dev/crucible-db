import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    Clock3,
    Database,
    FileCode2,
    Plus,
    ScrollText,
    ShieldCheck,
    Timer,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/crucible/empty-state';
import { PageHeader } from '@/components/crucible/page-header';
import { QueryRequestFilters } from '@/components/crucible/query-request-filters';
import { StatusBadge } from '@/components/crucible/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    formatDate,
    formatRemaining,
    statusLabel,
    visibleQueryRequestStatus,
} from '@/lib/crucible';
import type {
    QueryRequestFilterOptions,
    QueryRequestFilters as QueryRequestFiltersState,
    QueryRequestSummary,
} from '@/lib/crucible';
import { dashboard } from '@/routes';
import { create as createQueryRequest, show } from '@/routes/query-requests';
import type { Auth } from '@/types';

type DashboardProps = {
    stats: {
        connections: number;
        pending_reviews: number;
        scheduled: number;
        audit_events: number;
    };
    recent_requests: QueryRequestSummary[];
    recent_request_filters: QueryRequestFiltersState;
    recent_request_filter_options: QueryRequestFilterOptions;
    can_view_audit_events: boolean;
};

export default function Dashboard({
    stats,
    recent_requests,
    recent_request_filters,
    recent_request_filter_options,
    can_view_audit_events,
}: DashboardProps) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const userTimezone = auth.user.timezone ?? 'UTC';
    const [, setTimerTick] = useState(0);

    useEffect(() => {
        const interval = window.setInterval(() => {
            setTimerTick((value) => value + 1);
        }, 30000);

        return () => window.clearInterval(interval);
    }, []);

    const statItems = [
        {
            label: 'Connections',
            compactLabel: 'DBs',
            value: stats.connections,
            icon: Database,
            tone: 'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-900/70 dark:bg-orange-950/40 dark:text-orange-300',
        },
        {
            label: 'Pending Reviews',
            compactLabel: 'Reviews',
            value: stats.pending_reviews,
            icon: FileCode2,
            tone: 'text-amber-800 bg-amber-50 border-amber-200 dark:text-amber-300 dark:bg-amber-950/40 dark:border-amber-900/70',
        },
        {
            label: 'Scheduled',
            compactLabel: 'Scheduled',
            value: stats.scheduled,
            icon: Timer,
            tone: 'text-indigo-700 bg-indigo-50 border-indigo-200 dark:text-indigo-300 dark:bg-indigo-950/40 dark:border-indigo-900/70',
        },
        {
            label: 'Audit Events',
            adminOnly: true,
            compactLabel: 'Audit',
            value: stats.audit_events,
            icon: ScrollText,
            tone: 'text-emerald-700 bg-emerald-50 border-emerald-200 dark:text-emerald-300 dark:bg-emerald-950/40 dark:border-emerald-900/70',
        },
    ].filter((item) => !item.adminOnly || can_view_audit_events);

    return (
        <>
            <Head title="Dashboard" />

            <div className="crucible-page">
                <PageHeader
                    icon={ShieldCheck}
                    eyebrow="Control Plane"
                    title="Command Center"
                    description={`${stats.pending_reviews} reviews waiting, ${stats.scheduled} scheduled, ${stats.audit_events} audit events logged.`}
                    actions={
                        <Button asChild>
                            <Link href={createQueryRequest()}>
                                <Plus />
                                New Request
                            </Link>
                        </Button>
                    }
                />

                <div
                    className={`grid gap-2 sm:gap-3 ${
                        can_view_audit_events ? 'grid-cols-4' : 'grid-cols-3'
                    }`}
                >
                    {statItems.map((item) => (
                        <Card
                            key={item.label}
                            className="min-h-22 gap-2 py-2 sm:gap-4 sm:py-4"
                        >
                            <CardHeader className="flex flex-row items-start justify-between gap-1.5 space-y-0 px-2 pb-0 sm:gap-2 sm:px-4">
                                <div className="grid min-w-0 gap-1.5">
                                    <CardTitle className="text-[11px] leading-tight font-medium text-muted-foreground sm:text-sm">
                                        <span className="sm:hidden">
                                            {item.compactLabel}
                                        </span>
                                        <span className="hidden sm:inline">
                                            {item.label}
                                        </span>
                                    </CardTitle>
                                    <div className="text-2xl leading-none font-semibold tracking-normal sm:text-3xl">
                                        {item.value}
                                    </div>
                                    <div
                                        className={`flex size-6 items-center justify-center rounded-md border sm:hidden ${item.tone}`}
                                    >
                                        <item.icon className="size-3" />
                                    </div>
                                </div>
                                <div
                                    className={`hidden size-9 shrink-0 items-center justify-center rounded-lg border sm:flex ${item.tone}`}
                                >
                                    <item.icon className="size-4" />
                                </div>
                            </CardHeader>
                            <CardContent className="hidden px-4 sm:block">
                                <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <Activity className="size-3.5" />
                                    <span>Current workspace total</span>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-3 border-b px-4 pb-4 sm:px-6">
                        <div>
                            <CardTitle>Recent Query Requests</CardTitle>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Latest activity across review and execution.
                            </p>
                        </div>
                        <Button variant="outline" asChild>
                            <Link href={createQueryRequest()}>
                                <Plus />
                                New
                            </Link>
                        </Button>
                    </CardHeader>
                    <QueryRequestFilters
                        action={dashboard.url()}
                        clearHref={dashboard.url()}
                        filters={recent_request_filters}
                        options={recent_request_filter_options}
                    />
                    <CardContent className="p-0">
                        {recent_requests.length === 0 ? (
                            <div className="p-6">
                                <EmptyState
                                    icon={FileCode2}
                                    title="No query requests yet"
                                    detail="Create the first request to start a reviewable execution trail."
                                    action={
                                        <Button asChild size="sm">
                                            <Link href={createQueryRequest()}>
                                                <Plus />
                                                New Request
                                            </Link>
                                        </Button>
                                    }
                                />
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground uppercase">
                                            <th className="py-3 pr-4 pl-4 font-medium sm:pl-6">
                                                Request
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Connection
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Type
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Status
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Created
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Open
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {recent_requests.map((request) => (
                                            <tr
                                                key={request.id}
                                                className="border-b transition-colors last:border-0 hover:bg-accent/40"
                                            >
                                                <td className="py-3.5 pr-4 pl-4 sm:pl-6">
                                                    <Link
                                                        href={show(request.id)}
                                                        className="font-medium hover:text-primary"
                                                    >
                                                        {request.title}
                                                    </Link>
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {request.requester}
                                                    </div>
                                                </td>
                                                <td className="py-3.5 pr-4">
                                                    <span className="font-medium">
                                                        {request.connection}
                                                    </span>
                                                </td>
                                                <td className="py-3.5 pr-4">
                                                    <div className="flex flex-wrap gap-2">
                                                        <StatusBadge
                                                            value={
                                                                request.request_kind
                                                            }
                                                            label={
                                                                request.request_kind ===
                                                                'query_access'
                                                                    ? 'Query Access'
                                                                    : 'Single Execution'
                                                            }
                                                        />
                                                        <StatusBadge
                                                            value={
                                                                request.effective_query_type
                                                            }
                                                            label={statusLabel(
                                                                request.effective_query_type,
                                                            )}
                                                        />
                                                    </div>
                                                </td>
                                                <td className="py-3.5 pr-4">
                                                    <StatusBadge
                                                        value={visibleQueryRequestStatus(
                                                            request,
                                                        )}
                                                    />
                                                </td>
                                                <td className="py-3.5 pr-4 text-muted-foreground">
                                                    <span className="inline-flex items-center gap-1.5">
                                                        <Clock3 className="size-3.5" />
                                                        {request.active_session_expires_at
                                                            ? formatRemaining(
                                                                  request.active_session_expires_at,
                                                              )
                                                            : request.latest_session_expires_at
                                                              ? formatRemaining(
                                                                    request.latest_session_expires_at,
                                                                )
                                                              : formatDate(
                                                                    request.created_at,
                                                                    userTimezone,
                                                                )}
                                                    </span>
                                                </td>
                                                <td className="py-3.5 pr-4">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={show(
                                                                request.id,
                                                            )}
                                                            aria-label={`Open ${request.title}`}
                                                        >
                                                            <ArrowRight />
                                                        </Link>
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
