import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Clock3, FileCode2, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/crucible/empty-state';
import { PageHeader } from '@/components/crucible/page-header';
import { Pagination } from '@/components/crucible/pagination';
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

export default function QueryRequestsIndex({
    query_requests,
    filters,
    filter_options,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const userTimezone = auth.user.timezone ?? 'UTC';
    const [, setTimerTick] = useState(0);

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
                    eyebrow="Execution Queue"
                    title="Query Requests"
                    description={`${query_requests.total} requests in the current queue`}
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Plus />
                                New Request
                            </Link>
                        </Button>
                    }
                />

                <Card>
                    <CardHeader className="border-b px-4 pb-4 sm:px-6">
                        <CardTitle>Requests</CardTitle>
                    </CardHeader>
                    <QueryRequestFilters
                        action={index.url()}
                        clearHref={index.url()}
                        filters={filters}
                        options={filter_options}
                    />
                    <CardContent className="p-0">
                        {query_requests.data.length === 0 ? (
                            <div className="p-6">
                                <EmptyState
                                    icon={FileCode2}
                                    title="No query requests found"
                                    detail="Submit a request to create a reviewable SQL execution."
                                    action={
                                        <Button asChild size="sm">
                                            <Link href={create()}>
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
                                                <td className="py-3.5 pr-4 font-medium">
                                                    {request.connection}
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
                    </CardContent>
                </Card>
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
