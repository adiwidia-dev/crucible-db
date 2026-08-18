import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    Database,
    LoaderCircle,
    Plus,
    RotateCcw,
    Search,
    Server,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/crucible/empty-state';
import { PageHeader } from '@/components/crucible/page-header';
import { Pagination } from '@/components/crucible/pagination';
import { StatusBadge } from '@/components/crucible/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { driverLabel } from '@/lib/crucible';
import type { DatabaseConnectionSummary, Paginated } from '@/lib/crucible';
import { create, index, show } from '@/routes/connections';

type ConnectionFilters = {
    search: string;
    driver: string;
    status: string;
};

type Props = {
    connections: Paginated<DatabaseConnectionSummary>;
    filters: ConnectionFilters;
    driver_options: Array<{
        value: string;
        label: string;
    }>;
    connection_count: number;
    can_create: boolean;
};

export default function ConnectionsIndex({
    connections,
    filters,
    driver_options,
    connection_count,
    can_create,
}: Props) {
    const [pendingFilters, setPendingFilters] =
        useState<ConnectionFilters | null>(null);
    const [isFiltering, setIsFiltering] = useState(false);
    const filterValues = pendingFilters ?? filters;
    const hasActiveFilters = Boolean(
        filterValues.search || filterValues.driver || filterValues.status,
    );

    useEffect(() => {
        if (pendingFilters === null) {
            return;
        }

        const requestedFilters = pendingFilters;
        const timeout = window.setTimeout(() => {
            const query = Object.fromEntries(
                Object.entries(requestedFilters).filter(
                    ([, value]) => value !== '',
                ),
            );

            router.get(index.url(), query, {
                only: ['connections', 'filters', 'connection_count'],
                preserveScroll: true,
                preserveState: true,
                replace: true,
                onStart: () => setIsFiltering(true),
                onSuccess: () =>
                    setPendingFilters((current) => {
                        const requestIsStillCurrent =
                            current?.search === requestedFilters.search &&
                            current.driver === requestedFilters.driver &&
                            current.status === requestedFilters.status;

                        return requestIsStillCurrent ? null : current;
                    }),
                onFinish: () => setIsFiltering(false),
            });
        }, 300);

        return () => window.clearTimeout(timeout);
    }, [pendingFilters]);

    const updateFilter = (key: keyof ConnectionFilters, value: string) => {
        setPendingFilters((current) => ({
            ...(current ?? filters),
            [key]: value,
        }));
    };

    const resetFilters = () => {
        setPendingFilters({ search: '', driver: '', status: '' });
    };

    return (
        <>
            <Head title="Connections" />

            <div className="crucible-page">
                <PageHeader
                    icon={Database}
                    title="Connections"
                    description={`${connection_count} database ${connection_count === 1 ? 'connection' : 'connections'}`}
                    actions={
                        can_create && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus />
                                    New connection
                                </Link>
                            </Button>
                        )
                    }
                />

                <section
                    aria-labelledby="connection-registry-title"
                    className="min-w-0 overflow-hidden border-y bg-card sm:rounded-lg sm:border"
                >
                    <h2 id="connection-registry-title" className="sr-only">
                        Connection registry
                    </h2>

                    <div
                        role="search"
                        aria-label="Filter database connections"
                        aria-busy={isFiltering}
                        className="border-b px-3 py-3 sm:px-4"
                    >
                        <div className="grid gap-2 md:grid-cols-[minmax(16rem,1fr)_10rem_10rem] xl:grid-cols-[minmax(20rem,1fr)_11rem_11rem_auto]">
                            <div className="relative">
                                {isFiltering ? (
                                    <LoaderCircle className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 animate-spin text-primary motion-reduce:animate-none" />
                                ) : (
                                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                )}
                                <Input
                                    value={filterValues.search}
                                    onChange={(event) =>
                                        updateFilter(
                                            'search',
                                            event.currentTarget.value,
                                        )
                                    }
                                    placeholder="Search name, host, database, or user"
                                    aria-label="Search connections"
                                    className="bg-card pl-9"
                                />
                            </div>

                            <div className="relative">
                                <select
                                    value={filterValues.driver}
                                    onChange={(event) =>
                                        updateFilter(
                                            'driver',
                                            event.currentTarget.value,
                                        )
                                    }
                                    aria-label="Filter by driver"
                                    className="h-9 w-full appearance-none rounded-md border border-input bg-card px-3 pr-8 text-sm transition-[border-color,box-shadow] duration-150 ease-out outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/30 motion-reduce:transition-none"
                                >
                                    <option value="">All drivers</option>
                                    {driver_options.map((driver) => (
                                        <option
                                            key={driver.value}
                                            value={driver.value}
                                        >
                                            {driver.label}
                                        </option>
                                    ))}
                                </select>
                                <ChevronDown className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            </div>

                            <div className="relative">
                                <select
                                    value={filterValues.status}
                                    onChange={(event) =>
                                        updateFilter(
                                            'status',
                                            event.currentTarget.value,
                                        )
                                    }
                                    aria-label="Filter by status"
                                    className="h-9 w-full appearance-none rounded-md border border-input bg-card px-3 pr-8 text-sm transition-[border-color,box-shadow] duration-150 ease-out outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/30 motion-reduce:transition-none"
                                >
                                    <option value="">All statuses</option>
                                    <option value="active">Active</option>
                                    <option value="disabled">Disabled</option>
                                </select>
                                <ChevronDown className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            </div>

                            <div className="flex items-center justify-between gap-2 md:col-span-3 xl:col-span-1 xl:justify-end">
                                <span
                                    className="text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                                    aria-live="polite"
                                >
                                    {connections.from ?? 0}–
                                    {connections.to ?? 0} of {connections.total}
                                </span>
                                {hasActiveFilters && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={resetFilters}
                                    >
                                        <RotateCcw />
                                        Reset
                                    </Button>
                                )}
                            </div>
                        </div>
                    </div>

                    {connections.data.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                icon={Server}
                                title={
                                    hasActiveFilters
                                        ? 'No connections match these filters'
                                        : 'No connections found'
                                }
                                detail={
                                    hasActiveFilters
                                        ? 'Clear a filter or try a broader search.'
                                        : 'Register a database target before submitting query requests.'
                                }
                                action={
                                    hasActiveFilters ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={resetFilters}
                                        >
                                            <RotateCcw />
                                            Reset filters
                                        </Button>
                                    ) : (
                                        can_create && (
                                            <Button asChild size="sm">
                                                <Link href={create()}>
                                                    <Plus />
                                                    New connection
                                                </Link>
                                            </Button>
                                        )
                                    )
                                }
                            />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[820px] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/45 text-left text-xs text-muted-foreground">
                                        <th className="w-[26%] py-2.5 pr-4 pl-3 font-medium sm:pl-4">
                                            Name
                                        </th>
                                        <th className="w-[18%] py-2.5 pr-4 font-medium">
                                            Database
                                        </th>
                                        <th className="w-[24%] py-2.5 pr-4 font-medium">
                                            Host
                                        </th>
                                        <th className="py-2.5 pr-4 font-medium">
                                            Driver
                                        </th>
                                        <th className="py-2.5 pr-4 text-right font-medium">
                                            Requests
                                        </th>
                                        <th className="py-2.5 pr-4 font-medium">
                                            Status
                                        </th>
                                        <th className="w-12 py-2.5 pr-3">
                                            <span className="sr-only">
                                                Open
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {connections.data.map((connection) => (
                                        <tr
                                            key={connection.id}
                                            className="group border-b transition-colors duration-150 ease-out last:border-0 hover:bg-accent/55 motion-reduce:transition-none"
                                        >
                                            <td className="py-2.5 pr-4 pl-3 sm:pl-4">
                                                <Link
                                                    href={show(connection.id)}
                                                    prefetch
                                                    className="font-medium text-foreground transition-colors outline-none hover:text-primary focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring/40 motion-reduce:transition-none"
                                                >
                                                    {connection.name}
                                                </Link>
                                                <div className="mt-0.5 truncate text-xs text-muted-foreground">
                                                    {connection.username}
                                                </div>
                                            </td>
                                            <td className="py-2.5 pr-4 font-mono text-xs text-foreground">
                                                <div className="truncate">
                                                    {connection.database}
                                                </div>
                                            </td>
                                            <td className="py-2.5 pr-4 font-mono text-xs whitespace-nowrap text-muted-foreground">
                                                {connection.host}:
                                                {connection.port}
                                            </td>
                                            <td className="py-2.5 pr-4">
                                                <StatusBadge
                                                    value={connection.driver}
                                                    label={driverLabel(
                                                        connection.driver,
                                                    )}
                                                />
                                            </td>
                                            <td className="py-2.5 pr-4 text-right font-mono text-xs text-muted-foreground tabular-nums">
                                                {
                                                    connection.query_requests_count
                                                }
                                            </td>
                                            <td className="py-2.5 pr-4">
                                                <StatusBadge
                                                    value={
                                                        connection.is_active
                                                            ? 'active'
                                                            : 'disabled'
                                                    }
                                                />
                                            </td>
                                            <td className="py-2.5 pr-2 text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 text-muted-foreground group-hover:text-foreground"
                                                    asChild
                                                >
                                                    <Link
                                                        href={show(
                                                            connection.id,
                                                        )}
                                                        prefetch
                                                        aria-label={`Open ${connection.name}`}
                                                    >
                                                        <ChevronRight />
                                                    </Link>
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <Pagination pagination={connections} />
                </section>
            </div>
        </>
    );
}

ConnectionsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Connections',
            href: index(),
        },
    ],
};
