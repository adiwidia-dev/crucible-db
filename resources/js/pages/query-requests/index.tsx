import { Popover } from '@cloudflare/kumo/components/popover';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    ChevronDown,
    Clock3,
    FileCode2,
    Plus,
    RotateCcw,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/crucible/empty-state';
import { PageHeader } from '@/components/crucible/page-header';
import { Pagination } from '@/components/crucible/pagination';
import { QueryRequestFilters } from '@/components/crucible/query-request-filters';
import {
    SessionAccessBadge,
    StatusBadge,
} from '@/components/crucible/status-badge';
import { Button } from '@/components/ui/button';
import {
    formatDate,
    formatRemaining,
    queryRequestKindLabel,
    statusLabel,
    visibleQueryRequestStatus,
} from '@/lib/crucible';
import type {
    Paginated,
    QueryRequestFilterOptions,
    QueryRequestFilters as QueryRequestFiltersState,
    QueryRequestSummary,
} from '@/lib/crucible';
import { create, index, show } from '@/routes/query-requests';
import type { Auth } from '@/types';

type Props = {
    query_requests: Paginated<QueryRequestSummary>;
    filters: QueryRequestFiltersState;
    filter_options: QueryRequestFilterOptions;
};

const visibleConnectionLimit = 2;

function TargetConnections({
    connections,
}: {
    connections: QueryRequestSummary['connections'];
}) {
    const visibleConnections = connections.slice(0, visibleConnectionLimit);
    const hiddenConnectionCount =
        connections.length - visibleConnections.length;

    return (
        <div className="grid gap-0.5">
            {connections.length > 1 && (
                <div className="flex items-center gap-1.5 text-[11px]/4 font-semibold text-muted-foreground">
                    <span>{connections.length} targets</span>
                </div>
            )}
            <ul
                aria-label={`${connections.length} targeted ${connections.length === 1 ? 'connection' : 'connections'}`}
                className="list-disc space-y-0.5 pl-3.5 text-xs/4 font-medium marker:text-muted-foreground"
            >
                {visibleConnections.map((connection) => (
                    <li key={connection.id}>{connection.name}</li>
                ))}
            </ul>
            {hiddenConnectionCount > 0 && (
                <Popover>
                    <Popover.Trigger
                        render={
                            <button
                                type="button"
                                className="mt-1 inline-flex items-center gap-0.5 rounded-sm text-[11px]/4 font-semibold text-primary underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                            />
                        }
                        aria-label={`Show all ${connections.length} target connections`}
                    >
                        +{hiddenConnectionCount} more
                        <ChevronDown className="size-3" />
                    </Popover.Trigger>
                    <Popover.Content
                        side="bottom"
                        align="start"
                        positionMethod="fixed"
                        className="w-72 p-3"
                    >
                        <Popover.Title className="text-sm/5">
                            {connections.length} target connections
                        </Popover.Title>
                        <Popover.Description className="mt-0.5 text-xs/4">
                            Every database included in this request.
                        </Popover.Description>
                        <ul className="mt-2 max-h-56 list-disc space-y-1 overflow-y-auto pl-4 text-xs/4 font-medium marker:text-kumo-subtle">
                            {connections.map((connection) => (
                                <li key={connection.id} className="break-words">
                                    {connection.name}
                                </li>
                            ))}
                        </ul>
                    </Popover.Content>
                </Popover>
            )}
        </div>
    );
}

export default function QueryRequestsIndex({
    query_requests,
    filters,
    filter_options,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const userTimezone = auth.user.timezone ?? 'UTC';
    const [, setTimerTick] = useState(0);
    const hasActiveFilters = Object.values(filters).some(
        (filter) => filter !== '',
    );

    useEffect(() => {
        const interval = window.setInterval(() => {
            setTimerTick((value) => value + 1);
        }, 30000);

        return () => window.clearInterval(interval);
    }, []);

    return (
        <>
            <Head title="Query requests" />

            <div className="crucible-page">
                <PageHeader
                    icon={FileCode2}
                    title="Query Requests"
                    description={`${query_requests.total} request${query_requests.total === 1 ? '' : 's'} visible to you`}
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Plus />
                                New request
                            </Link>
                        </Button>
                    }
                />

                <section className="overflow-hidden rounded-lg border bg-card">
                    <QueryRequestFilters
                        action={index.url()}
                        clearHref={index.url()}
                        filters={filters}
                        options={filter_options}
                    />
                    <div>
                        {query_requests.data.length === 0 ? (
                            <div className="p-6">
                                <EmptyState
                                    icon={FileCode2}
                                    title={
                                        hasActiveFilters
                                            ? 'No requests match these filters'
                                            : 'No query requests yet'
                                    }
                                    detail={
                                        hasActiveFilters
                                            ? 'Clear a filter or broaden your search.'
                                            : 'Submit a deployment batch or query-access request to begin governed database work.'
                                    }
                                    action={
                                        hasActiveFilters ? (
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="outline"
                                            >
                                                <Link href={index()}>
                                                    <RotateCcw />
                                                    Reset filters
                                                </Link>
                                            </Button>
                                        ) : (
                                            <Button asChild size="sm">
                                                <Link href={create()}>
                                                    <Plus />
                                                    New request
                                                </Link>
                                            </Button>
                                        )
                                    }
                                />
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[760px] text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/35 text-left text-xs text-muted-foreground">
                                            <th className="py-3 pr-4 pl-4 font-medium sm:pl-6">
                                                Request
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Connections
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Type
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Status
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Window
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Open
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {query_requests.data.map((request) => (
                                            <tr
                                                key={request.id}
                                                className="border-b transition-colors last:border-0 hover:bg-accent/55"
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
                                                    <TargetConnections
                                                        connections={
                                                            request.connections
                                                        }
                                                    />
                                                </td>
                                                <td className="py-3.5 pr-4">
                                                    <div className="flex flex-wrap gap-2">
                                                        <StatusBadge
                                                            value={
                                                                request.request_kind
                                                            }
                                                            label={queryRequestKindLabel(
                                                                request.request_kind,
                                                                request.access_transport,
                                                            )}
                                                        />
                                                        {request.request_kind ===
                                                        'query_access' ? (
                                                            <>
                                                                <SessionAccessBadge
                                                                    mode={
                                                                        request.requested_access_mode
                                                                    }
                                                                />
                                                            </>
                                                        ) : (
                                                            <StatusBadge
                                                                value={
                                                                    request.effective_query_type
                                                                }
                                                                label={statusLabel(
                                                                    request.effective_query_type,
                                                                )}
                                                            />
                                                        )}
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
                                                                    request.scheduled_at,
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
                        <Pagination pagination={query_requests} />
                    </div>
                </section>
            </div>
        </>
    );
}

QueryRequestsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Query Requests',
            href: index(),
        },
    ],
};
